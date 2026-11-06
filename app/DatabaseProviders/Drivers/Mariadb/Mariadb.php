<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mariadb;

use App\DatabaseProviders\Drivers\AbstractDatabaseDriver;
use App\DatabaseProviders\Drivers\Shared\MysqlFamily;
use App\DTOs\Databases\ConfigField;
use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\DatabaseEndpoints;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\NodeResources;
use App\DTOs\Databases\ProcessManifest;
use App\DTOs\Databases\ProcessSpec;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\ProcessRestart;
use App\Models\DatabaseNode;
use App\Support\Databases\ClientPorts;
use App\Support\Databases\DefaultAccount;
use App\Support\Scripts\Literal;

final readonly class Mariadb extends AbstractDatabaseDriver
{
    public const string ROOT = '/srv/mariadb';

    public const string CONFIG = self::ROOT.'/conf/99-databasify.cnf';

    public const string NEW_CLUSTER_FLAG = self::ROOT.'/conf/new-cluster';

    public const string SERVER_SCRIPT = self::ROOT.'/conf/start-mariadbd';

    public const string RUNNING_CONFIG = self::ROOT.'/.running-config';

    public const int SST_PORT = 4444;

    public const int GALERA_PORT = 4567;

    public const int IST_PORT = 4568;

    private const string SERVER_UNIT = 'mariadb';

    private const string USER = 'mysql';

    private const int RESTART_SECONDS = 5;

    private const int GALERA_CACHE_FLOOR_MB = 256;

    private const int GALERA_CACHE_CEILING_MB = 4096;

    private const int GALERA_CACHE_STEP_MB = 64;

    private const int GALERA_CACHE_DISK_DIVISOR = 40;

    private const int GALERA_CACHE_MEMORY_DIVISOR = 4;

    private const array SQL_MODES = ['ALLOW_INVALID_DATES', 'ANSI', 'ANSI_QUOTES', 'EMPTY_STRING_IS_NULL', 'ERROR_FOR_DIVISION_BY_ZERO', 'HIGH_NOT_PRECEDENCE', 'IGNORE_BAD_TABLE_OPTIONS', 'IGNORE_SPACE', 'NO_AUTO_CREATE_USER', 'NO_AUTO_VALUE_ON_ZERO', 'NO_BACKSLASH_ESCAPES', 'NO_DIR_IN_CREATE', 'NO_ENGINE_SUBSTITUTION', 'NO_UNSIGNED_SUBTRACTION', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'ONLY_FULL_GROUP_BY', 'PAD_CHAR_TO_FULL_LENGTH', 'PIPES_AS_CONCAT', 'REAL_AS_FLOAT', 'SIMULTANEOUS_ASSIGNMENT', 'STRICT_ALL_TABLES', 'STRICT_TRANS_TABLES', 'TIME_ROUND_FRACTIONAL', 'TRADITIONAL'];

    public static function id(): string
    {
        return 'mariadb';
    }

    public function connection(): ConnectionProfile
    {
        return new ConnectionProfile(
            uriScheme: 'mysql',
            uriQuery: '?ssl-mode=REQUIRED',
            tlsParameter: 'sslmode',
            tlsMode: 'REQUIRED',
            command: 'mariadb -u {username} -p{password} -h {host} -P {port} -D {database} --ssl',
            fixedUsername: null,
            selectsDatabase: true,
            envKeys: ['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'database' => 'DB_DATABASE'],
            envNote: MysqlFamily::ENV_NOTE,
            commandNote: 'If the client cannot verify the server through your password, add --ssl-verify-server-cert=0. To verify the server with the CA certificate, download it and add --ssl-ca={ca}.',
        );
    }

    public function maxReadOnlyNodes(): int
    {
        return $this->primarySetting('max_connections');
    }

    public function processes(?DatabaseNode $node = null): ProcessManifest
    {
        return new ProcessManifest([$this->serverProcess()], $this->archiveSteps()->schedules());
    }

    public function configFiles(DatabaseNode $node): array
    {
        $serverId = (int) ip2long($node->privateIp());

        return [
            NodeFile::config(self::CONFIG, $this->render('files.server', [
                'port' => ClientPorts::CLIENT,
                'ip' => $node->privateIp(),
                'serverId' => $serverId,
                'gtidDomainId' => $serverId % 1000000 + 2,
                'readOnly' => $node->isReadOnly(),
                'clustered' => $this->database->isClustered(),
                'members' => $this->database->memberHosts(),
                'sstAuth' => Literal::ini('sst:'.$this->database->settings->secret('SST_PASSWORD')),
                'galeraCacheSize' => $this->galeraCacheSizeFor($node->resources()),
                'options' => array_map(Literal::ini(...), array_merge(['binlog_expire_logs_seconds' => MysqlFamily::BINLOG_RETENTION_SECONDS], $this->configured($node))),
            ]), self::USER),
            NodeFile::script(self::SERVER_SCRIPT, $this->render('lifecycle.server'), self::USER),
        ];
    }

    public function generateSecrets(): array
    {
        return array_merge([
            'SST_PASSWORD' => new_password(),
            'REPLICATION_PASSWORD' => new_password(),
        ], DefaultAccount::secrets());
    }

    public function environment(DatabaseEndpoints $endpoints): array
    {
        return DefaultAccount::environment($this->database, $endpoints, 'mariadb');
    }

    public function role(string $output): NodeRole
    {
        return match (true) {
            str_contains($output, 'WRITER ONLINE') => NodeRole::Primary,
            str_contains($output, 'MEMBER ONLINE'), str_contains($output, 'READ_REPLICA ONLINE') => NodeRole::Replica,
            default => NodeRole::Unknown,
        };
    }

    public function scriptVariables(): array
    {
        return [
            'configFile' => self::CONFIG,
            'serverScript' => self::SERVER_SCRIPT,
            'runningConfig' => self::RUNNING_CONFIG,
            'newClusterFlag' => self::NEW_CLUSTER_FLAG,
        ];
    }

    public function membershipVariables(): array
    {
        return ['hosts' => $this->database->memberHosts(), 'configPath' => self::CONFIG];
    }

    /**
     * @return list<ConfigField>
     */
    public function fields(): array
    {
        return array_merge(
            [ConfigField::flags('sql_mode', 'SQL mode', 'Which SQL rules the server enforces, such as strict inserts.', self::SQL_MODES, 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION')],
            MysqlFamily::fields(),
            [
                ConfigField::text('innodb_log_file_size', 'Redo log size', 'Size of the InnoDB redo log, such as 1G.', '[0-9]{1,12}[KMG]?', '')->tunedFromServer(),
                ConfigField::text('collation_server', 'Collation', 'Default collation for new databases and tables.', '((utf8mb4|utf8mb3|latin1|ascii)_[a-z0-9_]{1,40}|binary)', 'utf8mb4_uca1400_ai_ci'),
                ConfigField::integer('innodb_flush_neighbors', 'Flush neighbors', 'Flush neighbouring dirty pages together: 0 off, 1 contiguous, 2 same extent.', 0, 2, '1'),
                ConfigField::decimal('max_statement_time', 'Max statement time', 'Seconds a query may run before it is stopped. 0 never stops.', 0, 31536000, '0', 's'),
                ConfigField::integer('optimizer_prune_level', 'Optimizer pruning', 'Let the optimizer skip unpromising plans: 0 off, 1 heuristic, 2 aggressive.', 0, 2, '2'),
                ConfigField::integer('optimizer_search_depth', 'Optimizer search depth', 'How deep the optimizer searches join orders. 0 picks it automatically.', 0, 63, '62'),
                ConfigField::choice('innodb_force_primary_key', 'Require primary keys', 'Reject InnoDB tables created without a primary key.', MysqlFamily::switch(), 'OFF'),
            ],
        );
    }

    public function tunedSettings(NodeResources $resources): array
    {
        return array_merge(MysqlFamily::innodb($resources), [
            'innodb_log_file_size' => MysqlFamily::logCapacityMb($resources).'M',
        ]);
    }

    protected function accountSteps(): MariadbAccounts
    {
        return new MariadbAccounts($this, $this->database);
    }

    protected function nodeSteps(): MariadbNodes
    {
        return new MariadbNodes($this, $this->database);
    }

    protected function clusterSteps(): MariadbCluster
    {
        return new MariadbCluster($this, $this->database);
    }

    protected function archiveSteps(): MariadbArchive
    {
        return new MariadbArchive($this, $this->database);
    }

    protected function migrationSteps(): MariadbMigration
    {
        return new MariadbMigration($this, $this->database);
    }

    private function serverProcess(): ProcessSpec
    {
        return new ProcessSpec(
            self::SERVER_UNIT,
            [self::SERVER_SCRIPT],
            self::USER,
            restart: ProcessRestart::OnFailure,
            restartSeconds: self::RESTART_SECONDS,
            stopTimeoutSeconds: $this->stopTimeout(),
        );
    }

    private function galeraCacheSizeFor(NodeResources $resources): string
    {
        $limit = min(
            intdiv($resources->diskGb * 1024, self::GALERA_CACHE_DISK_DIVISOR),
            $resources->memoryShare(self::GALERA_CACHE_MEMORY_DIVISOR),
            self::GALERA_CACHE_CEILING_MB,
        );

        return max(self::GALERA_CACHE_FLOOR_MB, $limit - $limit % self::GALERA_CACHE_STEP_MB).'M';
    }
}
