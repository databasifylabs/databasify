<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Mysql;

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
use Illuminate\Support\Number;

final readonly class Mysql extends AbstractDatabaseDriver
{
    public const string ROOT = '/srv/mysql';

    public const string CONFIG = self::ROOT.'/conf/my.cnf';

    public const string RUN = self::ROOT.'/run';

    public const string SOCKET = self::RUN.'/mysqld.sock';

    public const int GROUP_PORT = 33061;

    private const string SERVER_UNIT = 'mysql';

    private const string USER = 'mysql';

    private const int RESTART_SECONDS = 5;

    private const string SMALLER_CHANGE_BUFFER_SINCE = '9.7';

    private const array TUNABLE_DEFAULTS = [
        'character_set_server' => 'utf8mb4',
        'collation_server' => 'utf8mb4_0900_ai_ci',
        'binlog_expire_logs_seconds' => MysqlFamily::BINLOG_RETENTION_SECONDS,
    ];

    private const array SQL_MODES = ['ALLOW_INVALID_DATES', 'ANSI_QUOTES', 'ERROR_FOR_DIVISION_BY_ZERO', 'HIGH_NOT_PRECEDENCE', 'IGNORE_SPACE', 'NO_AUTO_VALUE_ON_ZERO', 'NO_BACKSLASH_ESCAPES', 'NO_DIR_IN_CREATE', 'NO_ENGINE_SUBSTITUTION', 'NO_UNSIGNED_SUBTRACTION', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'ONLY_FULL_GROUP_BY', 'PAD_CHAR_TO_FULL_LENGTH', 'PIPES_AS_CONCAT', 'REAL_AS_FLOAT', 'STRICT_ALL_TABLES', 'STRICT_TRANS_TABLES', 'TIME_TRUNCATE_FRACTIONAL', 'TRADITIONAL', 'ANSI'];

    public static function id(): string
    {
        return 'mysql';
    }

    public function connection(): ConnectionProfile
    {
        return new ConnectionProfile(
            uriScheme: 'mysql',
            uriQuery: '?ssl-mode=REQUIRED',
            tlsParameter: 'sslmode',
            tlsMode: 'REQUIRED',
            command: 'mysql -u {username} -p{password} -h {host} -P {port} -D {database} --ssl-mode=REQUIRED',
            fixedUsername: null,
            selectsDatabase: true,
            envKeys: ['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'database' => 'DB_DATABASE'],
            envNote: MysqlFamily::ENV_NOTE,
            commandNote: 'With the MariaDB client, use --ssl --ssl-verify-server-cert=0 instead of --ssl-mode=REQUIRED. To verify the server, download the CA certificate and use --ssl-mode=VERIFY_IDENTITY --ssl-ca={ca}.',
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
        $settings = $this->database->settings;

        return [
            NodeFile::config(self::CONFIG, $this->render('files.server', [
                'port' => ClientPorts::CLIENT,
                'ip' => $node->privateIp(),
                'serverId' => ip2long($node->privateIp()),
                'clustered' => $this->database->isClustered(),
                'messageCacheSize' => (int) Number::clamp($node->resources()->memoryShare(20), 128, 4096).'M',
                'network' => $node->server->network?->cidr,
                'options' => array_map(Literal::ini(...), array_merge(self::TUNABLE_DEFAULTS, $this->configured($node))),
            ]), self::USER),
            NodeFile::secret(self::ROOT.'/conf/init.sql', $this->render('files.init', [
                'rootPassword' => Literal::sql($settings->secret('MYSQL_ROOT_PASSWORD')),
                'clusterAdminPassword' => Literal::sql($settings->secret('CLUSTER_ADMIN_PASSWORD')),
            ]), self::USER),
            NodeFile::secret(self::ROOT.'/conf/root.cnf', $this->render('files.client', ['password' => Literal::ini($settings->secret('MYSQL_ROOT_PASSWORD'))]), 'root'),
            NodeFile::secret(self::ROOT.'/conf/clusteradmin.pass', $settings->secret('CLUSTER_ADMIN_PASSWORD')."\n", 'root'),
        ];
    }

    public function generateSecrets(): array
    {
        return array_merge([
            'MYSQL_ROOT_PASSWORD' => new_password(),
            'CLUSTER_ADMIN_PASSWORD' => new_password(),
        ], DefaultAccount::secrets());
    }

    public function environment(DatabaseEndpoints $endpoints): array
    {
        return DefaultAccount::environment($this->database, $endpoints, 'mysql');
    }

    public function role(string $output): NodeRole
    {
        return match (true) {
            str_contains($output, 'PRIMARY ONLINE') => NodeRole::Primary,
            str_contains($output, 'SECONDARY ONLINE'), str_contains($output, 'READ_REPLICA ONLINE') => NodeRole::Replica,
            default => NodeRole::Unknown,
        };
    }

    public function scriptVariables(): array
    {
        return [
            'configFile' => self::CONFIG,
            'socket' => self::SOCKET,
            'runDirectory' => self::RUN,
            'groupPort' => self::GROUP_PORT,
        ];
    }

    public function membershipVariables(): array
    {
        return ['hosts' => $this->database->hosts()];
    }

    /**
     * @return list<ConfigField>
     */
    public function fields(): array
    {
        $switch = MysqlFamily::switch();

        return array_merge(
            [ConfigField::flags('sql_mode', 'SQL mode', 'Which SQL rules the server enforces, such as strict inserts and full GROUP BY.', self::SQL_MODES, 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION')],
            MysqlFamily::fields(),
            [
                ConfigField::text('innodb_redo_log_capacity', 'Redo log capacity', 'Disk space the redo log files may use, such as 1G.', '[0-9]{1,12}[KMG]?', '')->tunedFromServer(),
                ConfigField::text('collation_server', 'Collation', 'Default collation for new databases and tables.', '((utf8mb4|utf8mb3|latin1|ascii)_[a-z0-9_]{1,40}|binary)', 'utf8mb4_0900_ai_ci'),
                ConfigField::choice('end_markers_in_json', 'JSON end markers', 'Repeat the opening key as a comment at the end of JSON optimizer traces.', $switch, 'OFF'),
                ConfigField::integer('information_schema_stats_expiry', 'Statistics cache expiry', 'Seconds cached table statistics in INFORMATION_SCHEMA stay valid.', 0, 31536000, '86400', 's'),
                ConfigField::integer('innodb_change_buffer_max_size', 'Change buffer size', 'Share of the buffer pool the change buffer may use.', 0, 50, $this->changeBufferDefault(), '%'),
                ConfigField::integer('innodb_flush_neighbors', 'Flush neighbors', 'Flush neighbouring dirty pages together: 0 off, 1 contiguous, 2 same extent.', 0, 2, '0'),
                ConfigField::integer('innodb_thread_concurrency', 'Thread concurrency', 'Most threads inside InnoDB at once. 0 is unlimited.', 0, 1000, '0'),
                ConfigField::integer('innodb_online_alter_log_max_size', 'Online ALTER log size', 'Most temporary log space one online DDL operation may use.', 65536, 1099511627776, '134217728', 'B'),
                ConfigField::choice('internal_tmp_mem_storage_engine', 'Internal temporary tables', 'Storage engine for in-memory internal temporary tables.', ['TempTable', 'MEMORY'], 'TempTable'),
                ConfigField::integer('max_execution_time', 'Max execution time', 'Milliseconds a SELECT may run before it is stopped. 0 never stops.', 0, 4294967295, '0', 'ms'),
                ConfigField::integer('optimizer_prune_level', 'Optimizer pruning', 'Let the optimizer skip unpromising plans: 0 off, 1 on.', 0, 1, '1'),
                ConfigField::integer('optimizer_search_depth', 'Optimizer search depth', 'How deep the optimizer searches join orders. 0 picks it automatically.', 0, 62, '62'),
                ConfigField::text('optimizer_switch', 'Optimizer switches', 'Comma-separated flag=on|off pairs. Empty keeps the server defaults.', '([a-z_]{1,40}=(on|off|default)(,[a-z_]{1,40}=(on|off|default))*)?', ''),
                ConfigField::choice('sql_require_primary_key', 'Require primary keys', 'Reject tables created without a primary key.', $switch, 'OFF'),
                ConfigField::choice('windowing_use_high_precision', 'Precise window functions', 'Compute window functions without losing precision.', $switch, 'ON'),
                ConfigField::text('temptable_max_ram', 'TempTable memory', 'Memory the TempTable engine may use before it spills to disk, such as 1G.', '[0-9]{1,12}[KMG]?', '')->tunedFromServer(),
            ],
        );
    }

    public function tunedSettings(NodeResources $resources): array
    {
        return array_merge(MysqlFamily::innodb($resources), [
            'innodb_redo_log_capacity' => MysqlFamily::logCapacityMb($resources).'M',
            'innodb_page_cleaners' => (string) $resources->cpuShare(2, 1, 64),
            'innodb_parallel_read_threads' => (string) $resources->cpuShare(2, 1, 64),
            'temptable_max_ram' => max(64, $resources->memoryPercent(3)).'M',
        ]);
    }

    protected function accountSteps(): MysqlAccounts
    {
        return new MysqlAccounts($this, $this->database);
    }

    protected function nodeSteps(): MysqlNodes
    {
        return new MysqlNodes($this, $this->database);
    }

    protected function clusterSteps(): MysqlCluster
    {
        return new MysqlCluster($this, $this->database);
    }

    protected function archiveSteps(): MysqlArchive
    {
        return new MysqlArchive($this, $this->database);
    }

    protected function migrationSteps(): MysqlMigration
    {
        return new MysqlMigration($this, $this->database);
    }

    private function changeBufferDefault(): string
    {
        return version_compare($this->versionLine(), self::SMALLER_CHANGE_BUFFER_SINCE, '>=') ? '5' : '25';
    }

    private function serverProcess(): ProcessSpec
    {
        return new ProcessSpec(
            self::SERVER_UNIT,
            $this->shell('server'),
            self::USER,
            env: ['MYSQLD_PARENT_PID' => '1'],
            restart: ProcessRestart::OnFailure,
            restartSeconds: self::RESTART_SECONDS,
            stopTimeoutSeconds: $this->stopTimeout(),
        );
    }
}
