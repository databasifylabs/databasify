<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mongodb;

use App\DatabaseProviders\Drivers\AbstractDatabaseDriver;
use App\DTOs\Databases\ConfigField;
use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\DatabaseEndpoints;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\NodeResources;
use App\DTOs\Databases\ProcessManifest;
use App\DTOs\Databases\ProcessSpec;
use App\DTOs\Databases\ServiceConnection;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\ProcessRestart;
use App\Models\DatabaseNode;
use App\Support\Databases\ClientPorts;
use App\Support\Databases\DefaultAccount;
use Illuminate\Support\Number;

final readonly class Mongodb extends AbstractDatabaseDriver
{
    private const int MAX_REPLICA_SET_MEMBERS = 50;

    public const string ROOT = '/srv/mongodb';

    public const string CONFIG = self::ROOT.'/conf/mongod.conf';

    public const string DATA = self::ROOT.'/data';

    public const string KEYFILE = self::ROOT.'/conf/keyfile';

    public const string ROOT_PASSWORD = self::ROOT.'/conf/root-password';

    public const string MEMBERS = self::ROOT.'/conf/members.json';

    public const string OWN_ADDRESS = self::ROOT.'/conf/own-address';

    public const string RW_CONCERN = self::ROOT.'/conf/rw-concern.json';

    public const string RUNNING_CONFIG = self::ROOT.'/.running-config';

    public const string REPLICA_SET = 'databasify';

    public const string SERVER_UNIT = 'mongod';

    public const int MAXIMUM_VOTERS = 7;

    public const string CACHE_SIZE = 'cacheSizeGB';

    public const string MAX_CONNECTIONS = 'maxIncomingConnections';

    public const string SLOW_OPERATION = 'slowOpThresholdMs';

    public const string VERBOSITY = 'verbosity';

    public const string READ_CONCERN = 'defaultReadConcern';

    public const string WRITE_CONCERN = 'defaultWriteConcern';

    public const array CLUSTER_WIDE = [self::READ_CONCERN, self::WRITE_CONCERN];

    private const string USER = 'mongodb';

    private const string VERIFY_NOTE = 'To verify the server, download the CA certificate and replace tlsAllowInvalidCertificates=true with tlsCAFile=/path/to/{ca}.';

    private const int RESTART_SECONDS = 3;

    private const int RESERVED_MEMORY_MB = 1024;

    private const float MINIMUM_CACHE_GB = 0.25;

    private const int MINIMUM_CONNECTIONS = 100;

    private const int MAXIMUM_CONNECTIONS = 20000;

    public static function id(): string
    {
        return 'mongodb';
    }

    public function connection(): ConnectionProfile
    {
        $profile = new ConnectionProfile(
            uriScheme: 'mongodb',
            uriQuery: '?tls=true&tlsAllowInvalidCertificates=true&authSource=admin&directConnection=true',
            tlsParameter: 'tls',
            tlsMode: 'true',
            command: 'mongosh "mongodb://{username}:{password}@{host}:{port}/{database}?tls=true&tlsAllowInvalidCertificates=true&authSource=admin&directConnection=true"',
            fixedUsername: null,
            selectsDatabase: true,
            envKeys: ['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'database' => 'DB_DATABASE'],
            envNote: 'Users authenticate against the admin database: set authSource=admin, tls=true and tlsAllowInvalidCertificates=true in your MongoDB connection options. '.self::VERIFY_NOTE,
            commandNote: 'To verify the server, download the CA certificate and replace tlsAllowInvalidCertificates=true with tlsCAFile={ca}.',
        );

        return $this->database->dnsZone === null ? $profile : $profile->withService(new ServiceConnection(
            uriScheme: 'mongodb+srv',
            uriQuery: '?tls=true&tlsAllowInvalidCertificates=true',
            command: 'mongosh "mongodb+srv://{username}:{password}@{host}/{database}?tlsAllowInvalidCertificates=true"',
            envNote: 'Connect with the mongodb+srv:// scheme and no port: DNS lists every member, the replica set and the auth source, and the driver follows failovers itself. Set tlsAllowInvalidCertificates=true in your MongoDB connection options. '.self::VERIFY_NOTE,
            standbyUriQuery: '?tls=true&tlsAllowInvalidCertificates=true&readPreference=secondaryPreferred',
            standbyCommand: 'mongosh "mongodb+srv://{username}:{password}@{host}/{database}?tlsAllowInvalidCertificates=true&readPreference=secondaryPreferred"',
        ));
    }

    public function maxReadOnlyNodes(): int
    {
        return self::MAX_REPLICA_SET_MEMBERS - 1 - $this->maxStandbys();
    }

    public function processes(?DatabaseNode $node = null): ProcessManifest
    {
        $recovery = $this->database->pointInTimeRecovery;

        if ($recovery === null) {
            return new ProcessManifest([$this->serverProcess()]);
        }

        return new ProcessManifest([$this->serverProcess(), $this->archiveSteps()->agentProcess()], [$this->archiveSteps()->backupSchedule($recovery)]);
    }

    public function configFiles(DatabaseNode $node): array
    {
        $settings = $this->database->settings;

        return [
            NodeFile::config(self::CONFIG, $this->render('files.server', [
                'port' => ClientPorts::CLIENT,
                'options' => $this->filledValuesOf($this->configured($node)),
            ]), self::USER),
            NodeFile::secret(self::KEYFILE, $settings->secret('MONGODB_KEYFILE'), self::USER),
            NodeFile::secret(self::ROOT_PASSWORD, $settings->secret('MONGODB_ROOT_PASSWORD'), 'root'),
            NodeFile::secret(MongodbArchive::PASSWORD, $settings->secret('MONGODB_PBM_PASSWORD'), self::USER),
            NodeFile::config(self::OWN_ADDRESS, $this->clientAddress($node), self::USER),
            NodeFile::config(self::RW_CONCERN, json_encode($this->defaultRwConcern(), JSON_THROW_ON_ERROR)),
            NodeFile::secret(self::MEMBERS, json_encode($this->replicaSetMembers(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'root'),
        ];
    }

    public function generateSecrets(): array
    {
        return array_merge([
            'MONGODB_ROOT_PASSWORD' => new_password(),
            'MONGODB_KEYFILE' => new_password(),
            'MONGODB_PBM_PASSWORD' => new_password(),
        ], DefaultAccount::secrets());
    }

    public function environment(DatabaseEndpoints $endpoints): array
    {
        return DefaultAccount::environment($this->database, $endpoints, 'mongodb');
    }

    public function role(string $output): NodeRole
    {
        return match (true) {
            str_contains($output, 'role:primary') => NodeRole::Primary,
            str_contains($output, 'role:secondary') => NodeRole::Replica,
            default => NodeRole::Unknown,
        };
    }

    public function scriptVariables(): array
    {
        return [
            'configFile' => self::CONFIG,
            'keyfile' => self::KEYFILE,
            'rootPasswordFile' => self::ROOT_PASSWORD,
            'membersFile' => self::MEMBERS,
            'rwConcernFile' => self::RW_CONCERN,
            'runningConfig' => self::RUNNING_CONFIG,
            'replicaSet' => self::REPLICA_SET,
            'maximumVoters' => self::MAXIMUM_VOTERS,
            'clusterWideKeys' => self::CLUSTER_WIDE,
            'pbmAgentUnit' => MongodbArchive::AGENT_UNIT,
            'pbmPasswordFile' => MongodbArchive::PASSWORD,
            'migrationUnit' => MongodbMigration::UNIT,
            'migrationStateDir' => MongodbMigration::STATE_DIR,
            'checkpointDatabase' => MongodbMigration::CHECKPOINT_DATABASE,
        ];
    }

    public function membershipVariables(): array
    {
        return ['members' => $this->replicaSetMembers()];
    }

    /**
     * @return list<ConfigField>
     */
    public function fields(): array
    {
        return [
            ConfigField::choice(self::READ_CONCERN, 'Default read concern', 'Default consistency of reads that name no read concern.', ['local', 'available', 'majority'], 'local'),
            ConfigField::choice(self::WRITE_CONCERN, 'Default write concern', 'Acknowledgement writes wait for when they name no write concern.', ['majority', '1'], 'majority'),
            ConfigField::integer('transactionLifetimeLimitSeconds', 'Transaction lifetime', 'Seconds a multi-document transaction may stay open before it is aborted.', 1, 86400, '60', 's'),
            ConfigField::integer(self::SLOW_OPERATION, 'Slow operation threshold', 'Operations slower than this are logged.', 0, 3600000, '100', 'ms'),
            ConfigField::integer(self::VERBOSITY, 'Log verbosity', 'Log message verbosity level.', 0, 5, '0'),
        ];
    }

    public function tunedSettings(NodeResources $resources): array
    {
        return [
            self::CACHE_SIZE => $this->cacheGbFor($resources),
            self::MAX_CONNECTIONS => (string) Number::clamp($resources->memoryShare(2), self::MINIMUM_CONNECTIONS, self::MAXIMUM_CONNECTIONS),
        ];
    }

    protected function accountSteps(): MongodbAccounts
    {
        return new MongodbAccounts($this, $this->database);
    }

    protected function nodeSteps(): MongodbNodes
    {
        return new MongodbNodes($this, $this->database);
    }

    protected function clusterSteps(): MongodbCluster
    {
        return new MongodbCluster($this, $this->database);
    }

    protected function archiveSteps(): MongodbArchive
    {
        return new MongodbArchive($this, $this->database);
    }

    protected function migrationSteps(): MongodbMigration
    {
        return new MongodbMigration($this, $this->database);
    }

    /**
     * @return list<array{host: string, votes: int, priority: int, alias: ?string}>
     */
    private function replicaSetMembers(): array
    {
        $voters = $this->database->nodes()->members()->limit(self::MAXIMUM_VOTERS)->get();
        $records = $this->database->serviceRecords();

        return array_values($this->database->nodesWithServers()
            ->map(fn (DatabaseNode $node): array => $this->replicaSetMemberOf($node, $voters->contains($node) ? 1 : 0, $records?->aliasOf($node)))
            ->all());
    }

    /**
     * @return array{host: string, votes: int, priority: int, alias: ?string}
     */
    private function replicaSetMemberOf(DatabaseNode $node, int $weight, ?string $alias): array
    {
        return ['host' => $this->clientAddress($node), 'votes' => $weight, 'priority' => $weight, 'alias' => $alias];
    }

    private function serverProcess(): ProcessSpec
    {
        return new ProcessSpec(
            self::SERVER_UNIT,
            $this->shell('server'),
            self::USER,
            restart: ProcessRestart::OnFailure,
            restartSeconds: self::RESTART_SECONDS,
            stopTimeoutSeconds: $this->stopTimeout(),
        );
    }

    /**
     * @param  array<string, string>  $settings
     * @return array<string, string>
     */
    private function filledValuesOf(array $settings): array
    {
        return array_filter($settings, fn (string $value): bool => $value !== '');
    }

    private function cacheGbFor(NodeResources $resources): string
    {
        $halfOfFreeMemoryGb = ($resources->memoryMb - self::RESERVED_MEMORY_MB) / 2 / 1024;

        return number_format(max(self::MINIMUM_CACHE_GB, $halfOfFreeMemoryGb), 2, '.', '');
    }

    /**
     * @return array{defaultReadConcern: array{level: string}, defaultWriteConcern: array{w: int|string}}
     */
    private function defaultRwConcern(): array
    {
        $effective = collect($this->fields())
            ->mapWithKeys(fn (ConfigField $field): array => [$field->key => $field->default])
            ->merge($this->filledValuesOf($this->database->settings->config))
            ->all();

        return [
            self::READ_CONCERN => ['level' => $effective[self::READ_CONCERN]],
            self::WRITE_CONCERN => ['w' => $effective[self::WRITE_CONCERN] === '1' ? 1 : 'majority'],
        ];
    }
}
