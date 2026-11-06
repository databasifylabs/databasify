<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers;

use App\DatabaseProviders\Drivers\Shared\SentinelCluster;
use App\DatabaseProviders\Drivers\Shared\SentinelMigration;
use App\DatabaseProviders\Drivers\Shared\SentinelNodes;
use App\DTOs\Databases\ConfigField;
use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\DatabaseEndpoints;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\NodeResources;
use App\DTOs\Databases\ProcessManifest;
use App\DTOs\Databases\ProcessSpec;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\Persistence;
use App\Enums\Databases\SentinelFlavor;
use App\Models\DatabaseNode;
use App\Support\Scripts\Literal;
use Illuminate\Support\Collection;

abstract readonly class AbstractSentinelDriver extends AbstractDatabaseDriver
{
    public const int DATA_PORT = 6379;

    public const string SNAPSHOT_SCHEDULE = '3600 1';

    public const int SENTINEL_PORT = 26379;

    public const string MASTER_NAME = 'main';

    public const int FAILOVER_MINIMUM_NODES = 3;

    private const string PUBSUB_LIMIT_KEY = 'pubsub-output-buffer-limit';

    private const int RESTART_SECONDS = 3;

    private const int FAILOVER_RESTART_SECONDS = 15;

    private const int SENTINEL_STOP_SECONDS = 10;

    private const int OPEN_FILES_LIMIT = 65536;

    private const int RESERVED_FILES = 32;

    abstract public function flavor(): SentinelFlavor;

    public function clientAddress(DatabaseNode $node): string
    {
        return $node->privateIp().':'.self::DATA_PORT;
    }

    public function connection(): ConnectionProfile
    {
        $publicCertificate = $this->database->publicCertificateZone() !== null;
        $cli = "{$this->flavor()->value}-cli";

        return new ConnectionProfile(
            uriScheme: 'rediss',
            uriQuery: '',
            tlsParameter: 'tls',
            tlsMode: 'on',
            command: $publicCertificate
                ? "{$cli} --tls -u rediss://{username}:{password}@{host}:{port}"
                : "{$cli} --tls --insecure -u rediss://{username}:{password}@{host}:{port}",
            fixedUsername: 'default',
            selectsDatabase: false,
            envKeys: ['host' => 'REDIS_HOST', 'port' => 'REDIS_PORT', 'username' => null, 'password' => 'REDIS_PASSWORD', 'database' => null],
            envNote: $publicCertificate ? null : "phpredis verifies the server: to connect without the CA, add 'context' => ['stream' => ['verify_peer' => false, 'verify_peer_name' => false]] to the redis connections in config/database.php. To verify the server, download the CA certificate and use 'context' => ['stream' => ['cafile' => '/path/to/{ca}']] instead.",
            commandNote: $publicCertificate ? null : 'To verify the server, download the CA certificate and replace --insecure with --cacert {ca}.',
        );
    }

    public function maxReadOnlyNodes(): int
    {
        return $this->primarySetting('maxclients');
    }

    public function canFailover(Collection $nodes): bool
    {
        return $nodes->count() >= self::FAILOVER_MINIMUM_NODES && $nodes->filter(fn (DatabaseNode $node): bool => $node->isMember())->count() > 1;
    }

    public function processes(?DatabaseNode $node = null): ProcessManifest
    {
        if (! $this->database->isClustered()) {
            return new ProcessManifest([$this->serverProcess()]);
        }

        return new ProcessManifest([$this->serverProcess(), $this->sentinelProcess()]);
    }

    public function configFiles(DatabaseNode $node): array
    {
        return [
            $this->serverConfigOf($node),
            NodeFile::secret("{$this->root()}/conf/password", $this->password(), 'root'),
            ...$this->sentinelFilesOf($node),
        ];
    }

    private function serverConfigOf(DatabaseNode $node): NodeFile
    {
        return NodeFile::config($this->serverConfigPath(), $this->render('files.server', [
            'tlsPort' => $this->ports()->client,
            'password' => Literal::doubleQuoted($this->password()),
            'persistent' => $this->database->settings->persistence !== Persistence::Off,
            'options' => $this->directivesFor($this->configured($node)),
            'clustered' => $this->database->isClustered(),
            'ip' => $node->privateIp(),
            'minReplicas' => $this->database->nodes()->count() >= self::FAILOVER_MINIMUM_NODES ? 1 : 0,
            'readOnly' => $node->isReadOnly(),
            'primary' => $this->database->primaryOrSeed()->is($node) ? null : $this->database->primaryIp(),
        ]), $this->flavor()->value);
    }

    /**
     * @return list<NodeFile>
     */
    private function sentinelFilesOf(DatabaseNode $node): array
    {
        if (! $this->database->isClustered()) {
            return [];
        }

        return [NodeFile::secret("{$this->root()}/sentinel/sentinel.conf", $this->render('files.sentinel', [
            'ip' => $node->privateIp(),
            'password' => Literal::doubleQuoted($this->password()),
            'primary' => $this->database->primaryIp(),
            'quorum' => self::majorityOf($this->database->size()),
        ]), $this->flavor()->value)->keptIfPresent()];
    }

    /**
     * @param  array<string, string>  $configured
     * @return array<string, string>
     */
    private function directivesFor(array $configured): array
    {
        return collect($configured)
            ->mapWithKeys($this->directiveOf(...))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function directiveOf(string $value, string $key): array
    {
        if ($key === self::PUBSUB_LIMIT_KEY) {
            return ['client-output-buffer-limit' => 'pubsub '.(int) $value.'mb '.intdiv((int) $value, 4).'mb 60'];
        }

        return [$key => $value === '' ? '""' : $value];
    }

    public function generateSecrets(): array
    {
        return [$this->flavor()->env('PASSWORD') => new_password()];
    }

    public function environment(DatabaseEndpoints $endpoints): array
    {
        return [
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_SCHEME' => 'tls',
            'REDIS_HOST' => $endpoints->public->host,
            'REDIS_PORT' => (string) $endpoints->public->port,
            'REDIS_PASSWORD' => $this->password(),
        ];
    }

    public function role(string $output): NodeRole
    {
        return match (true) {
            str_contains($output, 'role:master') => NodeRole::Primary,
            str_contains($output, 'role:slave') && $this->isSyncedReplica($output) => NodeRole::Replica,
            default => NodeRole::Unknown,
        };
    }

    private function isSyncedReplica(string $output): bool
    {
        return str_contains($output, 'master_link_status:up') && str_contains($output, 'master_sync_in_progress:0');
    }

    public function membershipVariables(): array
    {
        $size = $this->database->size();

        return ['quorum' => self::majorityOf($size), 'requiredReplicas' => $size >= self::FAILOVER_MINIMUM_NODES ? 1 : 0];
    }

    public function scriptVariables(): array
    {
        $flavor = $this->flavor();

        return [
            'flavor' => $flavor->value,
            'cli' => $flavor->binary('cli'),
            'checkRdb' => $flavor->binary('check-rdb'),
            'versionField' => "{$flavor->value}_version",
            'serverUnit' => $flavor->serverUnit(),
            'sentinelUnit' => $flavor->sentinelUnit(),
            'dataPort' => self::DATA_PORT,
            'sentinelPort' => self::SENTINEL_PORT,
            'master' => self::MASTER_NAME,
            'snapshot' => self::SNAPSHOT_SCHEDULE,
        ];
    }

    /**
     * @return list<ConfigField>
     */
    public function fields(): array
    {
        return [
            ConfigField::choice('maxmemory-policy', 'Eviction policy', 'What happens when memory is full. noeviction rejects writes; the others remove keys to make room.', ['noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random', 'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl'], 'noeviction'),
            ConfigField::text('maxmemory', 'Max memory', 'Memory the dataset may use, such as 1433mb or 4gb.', '[0-9]{1,12}(kb|mb|gb)?', '')->tunedFromServer(),
            ConfigField::integer('io-threads', 'I/O threads', 'Threads that read and write client sockets.', 1, 128, '')->tunedFromServer()->restarting(),
            ConfigField::integer('maxclients', 'Max clients', 'Most client connections accepted at once.', 1, 1000000, '')->tunedFromServer(),
            ConfigField::integer('pubsub-output-buffer-limit', 'Pub/Sub output buffer', 'Hard output buffer limit for Pub/Sub clients. The soft limit is a quarter of it.', 32, 512, '32', 'MB'),
            ConfigField::integer('databases', 'Number of databases', 'Logical databases selectable with SELECT.', 1, 128, '16')->restarting(),
            ConfigField::integer('lfu-log-factor', 'LFU log factor', 'Counter logarithm factor for the LFU eviction policies.', 0, 100, '10'),
            ConfigField::integer('lfu-decay-time', 'LFU decay time', 'Minutes before an LFU counter is halved.', 0, 120, '1', 'min'),
            ConfigField::integer('timeout', 'Idle timeout', 'Seconds before an idle client is disconnected. 0 never disconnects.', 0, 31536000, '0', 's'),
            ConfigField::text('notify-keyspace-events', 'Keyspace notifications', 'Events published to keyspace channels, such as Ex for expired keys. Empty turns them off.', '[KEglshzxeAtmdn]{0,32}', ''),
            ConfigField::choice('acl-pubsub-default', 'Default channel access', 'Pub/Sub channels new users may use when their ACL names none.', ['resetchannels', 'allchannels'], 'resetchannels'),
            ConfigField::integer('active-expire-effort', 'Active expire effort', 'Effort spent removing expired keys in the background.', 1, 10, '1'),
            ConfigField::integer('slowlog-log-slower-than', 'Slow log threshold', 'Commands slower than this many microseconds are logged. -1 turns the slow log off.', -1, 1000000000, '10000', 'µs'),
            ConfigField::integer('slowlog-max-len', 'Slow log length', 'Number of slow commands kept.', 0, 100000, '128'),
        ];
    }

    public function tunedSettings(NodeResources $resources): array
    {
        return [
            'maxmemory' => $resources->memoryPercent($this->maxMemoryPercent($this->database->settings->persistence)).'mb',
            'io-threads' => (string) $resources->cpuShare(2, 1, 16),
            'maxclients' => (string) (self::OPEN_FILES_LIMIT - self::RESERVED_FILES),
        ];
    }

    protected function nodeSteps(): SentinelNodes
    {
        return new SentinelNodes($this, $this->database);
    }

    protected function clusterSteps(): SentinelCluster
    {
        return new SentinelCluster($this, $this->database);
    }

    protected function migrationSteps(): SentinelMigration
    {
        return new SentinelMigration($this, $this->database, $this->flavor());
    }

    private function password(): string
    {
        return $this->database->settings->secret($this->flavor()->env('PASSWORD'));
    }

    private function root(): string
    {
        return "/srv/{$this->flavor()->value}";
    }

    private function serverConfigPath(): string
    {
        return "{$this->root()}/conf/{$this->flavor()->value}.conf";
    }

    private function serverProcess(): ProcessSpec
    {
        return ProcessSpec::daemon($this->flavor()->serverUnit(), $this->underPrivateUmask([$this->flavor()->binary('server'), $this->serverConfigPath()]), $this->flavor()->value)
            ->restartingAfter($this->database->isClustered() ? self::FAILOVER_RESTART_SECONDS : self::RESTART_SECONDS)
            ->stoppedBy('SIGTERM', $this->stopTimeout());
    }

    private function sentinelProcess(): ProcessSpec
    {
        return ProcessSpec::daemon($this->flavor()->sentinelUnit(), $this->underPrivateUmask([$this->flavor()->binary('server'), "{$this->root()}/sentinel/sentinel.conf", '--sentinel']), $this->flavor()->value)
            ->after($this->flavor()->serverUnit())
            ->restartingAfter(self::RESTART_SECONDS)
            ->stoppedBy('SIGTERM', self::SENTINEL_STOP_SECONDS);
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function underPrivateUmask(array $argv): array
    {
        return ['sh', '-c', 'umask 0027 && exec "$@"', 'private-umask', ...$argv];
    }

    private function maxMemoryPercent(Persistence $persistence): int
    {
        return $persistence === Persistence::Off ? 85 : 70;
    }
}
