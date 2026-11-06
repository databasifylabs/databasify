<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Postgres;

use App\DatabaseProviders\Drivers\AbstractDatabaseDriver;
use App\DTOs\Databases\ConfigField;
use App\DTOs\Databases\ConnectionProfile;
use App\DTOs\Databases\DatabaseEndpoints;
use App\DTOs\Databases\NodeFile;
use App\DTOs\Databases\NodeResources;
use App\DTOs\Databases\ProcessManifest;
use App\DTOs\Databases\ProcessSpec;
use App\DTOs\Databases\Schedule;
use App\Enums\Databases\NodeRole;
use App\Models\DatabaseNode;
use App\Support\Databases\ClientPorts;
use App\Support\Databases\DefaultAccount;
use App\Support\Scripts\Literal;
use Illuminate\Support\Number;

final readonly class Postgres extends AbstractDatabaseDriver
{
    public const string ROOT = '/srv/postgres';

    public const string SOCKET_DIRECTORY = self::ROOT.'/run';

    public const int PATRONI_PORT = 8008;

    public const string PATRONI_SCOPE = 'main';

    public const int ETCD_CLIENT_PORT = 2379;

    public const int ETCD_PEER_PORT = 2380;

    /** @var list<string> */
    public const array CLUSTER_WIDE_KEYS = ['max_connections', 'max_locks_per_transaction', 'max_worker_processes', 'max_prepared_transactions', 'max_wal_senders', 'max_replication_slots', 'track_commit_timestamp', 'wal_level'];

    /** @var list<string> */
    private const array HBA_RULES = [
        'local all postgres peer',
        'local all all scram-sha-256',
        'host all all 127.0.0.1/32 scram-sha-256',
        'host all all ::1/128 scram-sha-256',
        'host replication replicator 127.0.0.1/32 scram-sha-256',
        'host replication replicator ::1/128 scram-sha-256',
        'hostssl replication replicator 0.0.0.0/0 scram-sha-256',
        'hostssl all all 0.0.0.0/0 scram-sha-256',
    ];

    private const array REPLICATION_PARAMETERS = [
        'wal_level' => 'replica',
        'hot_standby' => 'on',
        'wal_log_hints' => 'on',
        'max_wal_senders' => '20',
        'max_replication_slots' => '20',
        'wal_keep_size' => '1GB',
        'password_encryption' => 'scram-sha-256',
    ];

    private const array LOCAL_PARAMETERS = [
        'ssl' => 'on',
        'ssl_cert_file' => self::ROOT.'/tls/server.crt',
        'ssl_key_file' => self::ROOT.'/tls/server.key',
        'ssl_min_protocol_version' => 'TLSv1.2',
        'unix_socket_directories' => self::SOCKET_DIRECTORY,
        'log_destination' => 'stderr',
        'logging_collector' => 'off',
    ];

    private const string SERVER_UNIT = 'databasify-postgres';

    private const string USER = 'postgres';

    private const string PATRONI_UNIT = 'databasify-patroni';

    private const string ETCD_UNIT = 'databasify-etcd';

    private const string POOLER_UNIT = 'databasify-pgbouncer';

    private const string LOCKS_UNIT = 'databasify-postgres-locks';

    private const int RESTART_SECONDS = 3;

    private const array SWITCH = ['on', 'off'];

    private const string MEMORY = '[0-9]{1,9}(kB|MB|GB)';

    private const int MIN_CONNECTIONS = 100;

    private const int CONNECTION_MB = 32;

    private const int MIN_SLOT_WAL_MB = 1024;

    private const int CLONE_HEADROOM = 2;

    private const string RELOADS_AUTOVACUUM_WORKERS_FROM = '18';

    public static function id(): string
    {
        return 'postgres';
    }

    public static function maxConnectionsFor(NodeResources $resources): int
    {
        return max(self::MIN_CONNECTIONS, $resources->memoryShare(self::CONNECTION_MB));
    }

    public function connection(): ConnectionProfile
    {
        return new ConnectionProfile(
            uriScheme: 'postgresql',
            uriQuery: '?sslmode=require',
            tlsParameter: 'sslmode',
            tlsMode: 'require',
            command: 'PGSSLMODE=require PGPASSWORD={password} psql -U {username} -h {host} -p {port} -d {database}',
            fixedUsername: null,
            selectsDatabase: true,
            envKeys: ['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'database' => 'DB_DATABASE'],
            envNote: 'To verify the server, download the CA certificate and use sslmode=verify-full with sslrootcert=/path/to/{ca}.',
            commandNote: 'To verify the server, download the CA certificate and use PGSSLMODE=verify-full PGSSLROOTCERT={ca}.',
        );
    }

    public function maxReadOnlyNodes(): int
    {
        $senders = min($this->replicationSetting('max_wal_senders'), $this->replicationSetting('max_replication_slots'));

        return $senders - $this->maxStandbys() - self::CLONE_HEADROOM;
    }

    public function processes(?DatabaseNode $node = null): ProcessManifest
    {
        return new ProcessManifest($this->processesOn($node), $this->schedules());
    }

    public function configFiles(DatabaseNode $node): array
    {
        if (! $this->database->isClustered()) {
            return [...$this->standaloneFilesOf($node), ...$this->poolerFilesOf($node)];
        }

        return [$this->patroniFileOf($node), ...$this->etcdFilesOf($node), ...$this->poolerFilesOf($node)];
    }

    /**
     * @return list<NodeFile>
     */
    private function standaloneFilesOf(DatabaseNode $node): array
    {
        return [
            NodeFile::config(self::ROOT.'/conf/postgresql.conf', $this->render('files.server', ['port' => ClientPorts::CLIENT, ...$this->parametersOf($node)]), self::USER),
            NodeFile::config(self::ROOT.'/conf/pg_hba.conf', $this->render('files.hba', ['rules' => self::HBA_RULES]), self::USER),
        ];
    }

    private function patroniFileOf(DatabaseNode $node): NodeFile
    {
        $settings = $this->database->settings;

        return NodeFile::secret(self::ROOT.'/conf/patroni.yml', $this->render('files.patroni', [
            'ip' => $node->privateIp(),
            'port' => ClientPorts::CLIENT,
            'versionLine' => $this->versionLine(),
            'readOnly' => $node->isReadOnly(),
            'etcdHosts' => $this->database->membersWithServers()->map(fn (DatabaseNode $member): string => "{$member->privateIp()}:".self::ETCD_CLIENT_PORT)->implode(','),
            'patroniPassword' => Literal::singleQuoted($settings->secret('PATRONI_API_PASSWORD')),
            'postgresPassword' => Literal::singleQuoted($settings->secret('POSTGRES_PASSWORD')),
            'replicationPassword' => Literal::singleQuoted($settings->secret('REPLICATION_PASSWORD')),
            'rules' => self::HBA_RULES,
            ...$this->parametersOf($node),
        ]), self::USER);
    }

    /**
     * @return list<NodeFile>
     */
    private function poolerFilesOf(DatabaseNode $node): array
    {
        $resources = $node->resources();

        return [
            NodeFile::config(self::ROOT.'/conf/pgbouncer.ini', $this->render('files.pgbouncer', [
                'port' => ClientPorts::CLIENT,
                'pools' => $this->database->connectionPools()->get(),
                'maxClientConnections' => max(5000, self::maxConnectionsFor($resources) * 10),
                'defaultPoolSize' => (int) Number::clamp($resources->cpus * 2, 10, 100),
            ]), self::USER),
            NodeFile::secret(self::ROOT.'/conf/pgbouncer-users.txt', $this->render('files.pgbouncer-users', [
                'password' => $this->database->settings->secret('PGBOUNCER_AUTH_PASSWORD'),
            ]), self::USER),
        ];
    }

    /**
     * @return list<NodeFile>
     */
    private function etcdFilesOf(DatabaseNode $node): array
    {
        if ($node->isReadOnly()) {
            return [];
        }

        $peers = $this->database->nodes()->members()->where('position', '<=', $node->position)->get();

        return [NodeFile::config(self::ROOT.'/conf/etcd.env', $this->render('files.etcd', [
            'ip' => $node->privateIp(),
            'initialCluster' => $peers->map(fn (DatabaseNode $peer): string => "{$peer->privateIp()}=http://{$peer->privateIp()}:".self::ETCD_PEER_PORT)->implode(','),
            'state' => $peers->count() === 1 ? 'new' : 'existing',
            'databaseId' => $this->database->id,
        ]), self::USER)];
    }

    /**
     * @return array{clusterWide: array<string, string>, local: array<string, string>}
     */
    private function parametersOf(DatabaseNode $node): array
    {
        $configured = $this->configured($node);
        $clusterWide = array_flip(self::CLUSTER_WIDE_KEYS);
        $archiving = $this->database->pointInTimeRecovery === null ? [] : [
            'archive_mode' => 'on',
            'archive_command' => PostgresArchive::archiveCommand(),
            'archive_timeout' => '60',
        ];

        return [
            'clusterWide' => array_map(Literal::singleQuoted(...), array_merge(self::REPLICATION_PARAMETERS, array_intersect_key($configured, $clusterWide))),
            'local' => array_map(Literal::singleQuoted(...), array_merge(self::LOCAL_PARAMETERS, $archiving, array_diff_key($configured, $clusterWide))),
        ];
    }

    public function generateSecrets(): array
    {
        return array_merge([
            'POSTGRES_PASSWORD' => new_password(),
            'REPLICATION_PASSWORD' => new_password(),
            'PATRONI_API_PASSWORD' => new_password(),
            'PGBOUNCER_AUTH_PASSWORD' => new_password(),
        ], DefaultAccount::secrets());
    }

    public function environment(DatabaseEndpoints $endpoints): array
    {
        return array_merge(DefaultAccount::environment($this->database, $endpoints, 'pgsql'), ['DB_SSLMODE' => 'require']);
    }

    public function role(string $output): NodeRole
    {
        if (! str_contains($output, 'state:running') && ! str_contains($output, 'state:streaming')) {
            return NodeRole::Unknown;
        }

        return match (true) {
            str_contains($output, 'role:primary') => NodeRole::Primary,
            str_contains($output, 'role:replica'), str_contains($output, 'role:standby') => NodeRole::Replica,
            default => NodeRole::Unknown,
        };
    }

    public function scriptVariables(): array
    {
        return [
            'socketDirectory' => self::SOCKET_DIRECTORY,
            'poolPort' => ClientPorts::POOL,
            'patroniPort' => self::PATRONI_PORT,
            'patroniScope' => self::PATRONI_SCOPE,
            'etcdClientPort' => self::ETCD_CLIENT_PORT,
            'etcdPeerPort' => self::ETCD_PEER_PORT,
        ];
    }

    public function membershipVariables(): array
    {
        return ['hosts' => $this->database->memberHosts()];
    }

    /**
     * @return list<ConfigField>
     */
    public function fields(): array
    {
        return [
            ConfigField::integer('max_connections', 'Max connections', 'Most client connections accepted at once.', 25, 262143, '')->tunedFromServer()->restarting(),
            ConfigField::text('shared_buffers', 'Shared buffers', 'Memory PostgreSQL uses to cache data pages, such as 512MB.', self::MEMORY, '')->tunedFromServer()->restarting(),
            ConfigField::text('effective_cache_size', 'Effective cache size', 'Memory the planner assumes the OS and PostgreSQL use to cache data.', self::MEMORY, '')->tunedFromServer(),
            ConfigField::text('work_mem', 'Work memory', 'Memory each sort or hash step may use before spilling to disk.', self::MEMORY, '')->tunedFromServer(),
            ConfigField::text('maintenance_work_mem', 'Maintenance memory', 'Memory VACUUM, CREATE INDEX and similar commands may use.', self::MEMORY, '')->tunedFromServer(),
            ConfigField::integer('max_worker_processes', 'Max background workers', 'Most background worker processes.', 1, 262143, '')->tunedFromServer()->restarting(),
            ConfigField::integer('max_parallel_workers', 'Max parallel workers', 'Most workers all parallel operations may use at once.', 0, 1024, '')->tunedFromServer(),
            ConfigField::integer('max_parallel_workers_per_gather', 'Parallel workers per query', 'Workers one query may use for a parallel scan.', 0, 1024, '')->tunedFromServer(),
            ConfigField::integer('max_parallel_maintenance_workers', 'Parallel maintenance workers', 'Workers one CREATE INDEX or VACUUM may use.', 0, 1024, '')->tunedFromServer(),
            ConfigField::decimal('random_page_cost', 'Random page cost', 'Planner cost of a random page read. Lower suits SSDs.', 0, 1000, '')->tunedFromServer(),
            ConfigField::integer('effective_io_concurrency', 'I/O concurrency', 'Concurrent disk reads the planner expects the storage to handle.', 0, 1000, '')->tunedFromServer(),
            ConfigField::integer('autovacuum_freeze_max_age', 'Autovacuum freeze age', 'Transaction age that forces a VACUUM to prevent wraparound.', 200000000, 1500000000, '200000000')->restarting(),
            $this->autovacuumWorkersField(),
            ConfigField::integer('autovacuum_naptime', 'Autovacuum naptime', 'Seconds between autovacuum runs on a database.', 1, 86400, '60', 's'),
            ConfigField::integer('autovacuum_vacuum_threshold', 'Vacuum threshold', 'Updated or deleted rows needed to trigger a VACUUM on a table.', 0, 2147483647, '50'),
            ConfigField::integer('autovacuum_analyze_threshold', 'Analyze threshold', 'Changed rows needed to trigger an ANALYZE on a table.', 0, 2147483647, '50'),
            ConfigField::decimal('autovacuum_vacuum_scale_factor', 'Vacuum scale factor', 'Share of a table added to the vacuum threshold.', 0, 1, '0.2'),
            ConfigField::decimal('autovacuum_analyze_scale_factor', 'Analyze scale factor', 'Share of a table added to the analyze threshold.', 0, 1, '0.1'),
            ConfigField::integer('autovacuum_vacuum_cost_delay', 'Vacuum cost delay', 'Milliseconds autovacuum sleeps when it reaches its cost limit. -1 uses vacuum_cost_delay.', -1, 100, '2', 'ms'),
            ConfigField::integer('autovacuum_vacuum_cost_limit', 'Vacuum cost limit', 'Cost that makes autovacuum sleep. -1 uses vacuum_cost_limit.', -1, 10000, '-1'),
            ConfigField::integer('bgwriter_delay', 'Background writer delay', 'Milliseconds between background writer rounds.', 10, 10000, '200', 'ms'),
            ConfigField::text('bgwriter_flush_after', 'Background writer flush', 'Data written by the background writer before it asks the OS to flush, such as 512kB.', '[0-9]{1,4}(kB|MB)', '512kB'),
            ConfigField::integer('bgwriter_lru_maxpages', 'Background writer pages', 'Most pages the background writer writes per round.', 0, 1073741823, '100'),
            ConfigField::decimal('bgwriter_lru_multiplier', 'Background writer multiplier', 'How far ahead of demand the background writer cleans pages.', 0, 10, '2'),
            ConfigField::integer('deadlock_timeout', 'Deadlock timeout', 'Milliseconds to wait on a lock before checking for a deadlock.', 500, 1800000, '1000', 'ms'),
            ConfigField::choice('default_toast_compression', 'TOAST compression', 'Compression for large column values.', ['pglz', 'lz4'], 'pglz'),
            ConfigField::integer('idle_in_transaction_session_timeout', 'Idle transaction timeout', 'Milliseconds a session may sit idle inside a transaction before it is closed. 0 never closes.', 0, 604800000, '0', 'ms'),
            ConfigField::integer('statement_timeout', 'Statement timeout', 'Milliseconds a statement may run before it is cancelled. 0 never cancels.', 0, 2147483647, '0', 'ms'),
            ConfigField::integer('lock_timeout', 'Lock timeout', 'Milliseconds a statement waits for a lock before giving up. 0 waits forever.', 0, 2147483647, '0', 'ms'),
            ConfigField::choice('jit', 'JIT compilation', 'Compile parts of expensive queries to machine code.', self::SWITCH, 'on'),
            ConfigField::integer('log_autovacuum_min_duration', 'Log slow autovacuum', 'Autovacuum runs slower than this many milliseconds are logged. -1 turns it off.', -1, 2147483647, '600000', 'ms'),
            ConfigField::choice('log_error_verbosity', 'Error log detail', 'How much detail each logged message includes.', ['terse', 'default', 'verbose'], 'default'),
            ConfigField::integer('log_min_duration_statement', 'Slow query log', 'Statements slower than this many milliseconds are logged. -1 turns it off.', -1, 86400000, '-1', 'ms'),
            ConfigField::integer('max_files_per_process', 'Max files per process', 'Most files each server process may keep open.', 1000, 4096, '1000')->restarting(),
            ConfigField::integer('max_locks_per_transaction', 'Locks per transaction', 'Average object locks each transaction may hold.', 64, 6400, '64')->restarting(),
            ConfigField::integer('max_pred_locks_per_transaction', 'Predicate locks per transaction', 'Average predicate locks each serializable transaction may hold.', 64, 640, '64')->restarting(),
            ConfigField::integer('max_prepared_transactions', 'Prepared transactions', 'Most transactions in the prepared state at once.', 0, 10000, '0')->restarting(),
            ConfigField::integer('max_logical_replication_workers', 'Logical replication workers', 'Most logical replication workers.', 4, 64, '4')->restarting(),
            ConfigField::integer('max_replication_slots', 'Replication slots', 'Most replication slots.', 8, 64, '10')->restarting(),
            ConfigField::integer('max_wal_senders', 'WAL senders', 'Most concurrent WAL sender processes.', 10, 64, '10')->restarting(),
            ConfigField::text('max_slot_wal_keep_size', 'Slot WAL retention', 'WAL a replication slot may hold before the replica must re-clone, such as 4096MB. -1 is unlimited.', '(-1|[0-9]{1,7}(MB|GB))', '')->tunedFromServer(),
            ConfigField::text('max_stack_depth', 'Max stack depth', 'Deepest execution stack a session may use, such as 2MB.', '[0-9]{1,4}(kB|MB)', '2MB'),
            ConfigField::integer('max_standby_archive_delay', 'Standby archive delay', 'Milliseconds a standby waits before cancelling queries that conflict with archived WAL.', -1, 43200000, '30000', 'ms'),
            ConfigField::integer('max_standby_streaming_delay', 'Standby streaming delay', 'Milliseconds a standby waits before cancelling queries that conflict with streamed WAL.', -1, 43200000, '30000', 'ms'),
            ConfigField::integer('temp_file_limit', 'Temporary file limit', 'Kilobytes of temporary files each process may use. -1 is unlimited.', -1, 2147483647, '-1', 'kB'),
            ConfigField::text('timezone', 'Time zone', 'Default time zone, such as UTC or Asia/Dhaka.', '[A-Za-z_]{1,20}(/[A-Za-z_+-]{1,30}){0,2}', 'UTC'),
            ConfigField::integer('track_activity_query_size', 'Tracked query size', 'Bytes of each running query kept for pg_stat_activity.', 1024, 10240, '1024', 'B')->restarting(),
            ConfigField::choice('track_commit_timestamp', 'Track commit time', 'Record the commit time of each transaction.', self::SWITCH, 'off')->restarting(),
            ConfigField::choice('track_functions', 'Track functions', 'Which function calls are counted in statistics.', ['none', 'pl', 'all'], 'none'),
            ConfigField::choice('track_io_timing', 'Track I/O timing', 'Time disk reads and writes in statistics.', self::SWITCH, 'off'),
            ConfigField::choice('wal_level', 'WAL level', 'Logical writes the extra WAL that logical replication and continuous migrations from this database need.', ['replica', 'logical'], 'replica')->restarting(),
            ConfigField::integer('wal_sender_timeout', 'WAL sender timeout', 'Milliseconds before an inactive replication connection is closed.', 0, 10800000, '60000', 'ms'),
            ConfigField::integer('wal_writer_delay', 'WAL writer delay', 'Milliseconds between WAL writer flushes.', 10, 200, '200', 'ms'),
            ConfigField::choice('default_transaction_isolation', 'Isolation level', 'Isolation level of new transactions.', ['read committed', 'repeatable read', 'serializable'], 'read committed'),
        ];
    }

    public function tunedSettings(NodeResources $resources): array
    {
        $sharedBuffers = $resources->memoryPercent(25);
        $connections = self::maxConnectionsFor($resources);

        return [
            'max_connections' => (string) $connections,
            'shared_buffers' => "{$sharedBuffers}MB",
            'effective_cache_size' => $resources->memoryPercent(75).'MB',
            'work_mem' => (int) Number::clamp(intdiv($resources->memoryMb - $sharedBuffers, $connections * 3), 4, 256).'MB',
            'maintenance_work_mem' => min($resources->memoryShare(16), 2048).'MB',
            'max_worker_processes' => (string) max(8, $resources->cpus),
            'max_parallel_workers' => (string) max(2, $resources->cpus),
            'max_parallel_workers_per_gather' => (string) $resources->cpuShare(2, 1, 8),
            'max_parallel_maintenance_workers' => (string) $resources->cpuShare(2, 1, 8),
            'random_page_cost' => '1.1',
            'effective_io_concurrency' => '200',
            'max_slot_wal_keep_size' => $this->slotWalFor($resources).'MB',
        ];
    }

    protected function accountSteps(): PostgresAccounts
    {
        return new PostgresAccounts($this, $this->database);
    }

    protected function nodeSteps(): PostgresNodes
    {
        return new PostgresNodes($this, $this->database);
    }

    protected function clusterSteps(): PostgresCluster
    {
        return new PostgresCluster($this, $this->database);
    }

    protected function archiveSteps(): PostgresArchive
    {
        return new PostgresArchive($this, $this->database);
    }

    protected function migrationSteps(): PostgresMigration
    {
        return new PostgresMigration($this, $this->database);
    }

    /**
     * @return list<ProcessSpec>
     */
    private function processesOn(?DatabaseNode $node): array
    {
        if ($node === null) {
            return [$this->locksProcess(), $this->etcdProcess(), $this->patroniProcess(), $this->serverProcess(), $this->poolerProcess()];
        }

        if (! $this->database->isClustered()) {
            return [$this->locksProcess(), $this->serverProcess(), $this->poolerProcess()];
        }

        if ($node->isReadOnly()) {
            return [$this->locksProcess(), $this->patroniProcess(), $this->poolerProcess()];
        }

        return [$this->locksProcess(), $this->etcdProcess(), $this->patroniProcess(), $this->poolerProcess()];
    }

    /**
     * @return list<Schedule>
     */
    private function schedules(): array
    {
        $recovery = $this->database->pointInTimeRecovery;

        return $recovery === null ? [] : [$this->archiveSteps()->backupSchedule($recovery)];
    }

    private function locksProcess(): ProcessSpec
    {
        return ProcessSpec::oneshot(self::LOCKS_UNIT, $this->shell('locks'), self::USER)
            ->enabledAtBoot();
    }

    private function serverProcess(): ProcessSpec
    {
        return ProcessSpec::daemon(self::SERVER_UNIT, ["/usr/lib/postgresql/{$this->versionLine()}/bin/postgres", '-D', self::ROOT.'/data', '-c', 'config_file='.self::ROOT.'/conf/postgresql.conf'], self::USER)
            ->after(self::LOCKS_UNIT)
            ->restartingAfter(self::RESTART_SECONDS)
            ->stoppedBy('SIGINT', $this->stopTimeout());
    }

    private function patroniProcess(): ProcessSpec
    {
        return ProcessSpec::daemon(self::PATRONI_UNIT, ['/usr/bin/patroni', self::ROOT.'/conf/patroni.yml'], self::USER)
            ->after(self::LOCKS_UNIT, self::ETCD_UNIT)
            ->restartingAfter(self::RESTART_SECONDS)
            ->stoppedBy('SIGTERM', $this->stopTimeout());
    }

    private function poolerProcess(): ProcessSpec
    {
        return ProcessSpec::daemon(self::POOLER_UNIT, ['/usr/sbin/pgbouncer', self::ROOT.'/conf/pgbouncer.ini'], self::USER)
            ->after(self::SERVER_UNIT, self::PATRONI_UNIT)
            ->restartingAfter(self::RESTART_SECONDS);
    }

    private function etcdProcess(): ProcessSpec
    {
        return ProcessSpec::daemon(self::ETCD_UNIT, $this->shell('etcd'), self::USER)
            ->restartingAfter(self::RESTART_SECONDS);
    }

    private function autovacuumWorkersField(): ConfigField
    {
        $field = ConfigField::integer('autovacuum_max_workers', 'Autovacuum workers', 'Most autovacuum processes running at once.', 1, 20, '3');

        return version_compare($this->versionLine(), self::RELOADS_AUTOVACUUM_WORKERS_FROM, '<') ? $field->restarting() : $field;
    }

    private function replicationSetting(string $key): int
    {
        return $this->primarySetting($key, self::REPLICATION_PARAMETERS[$key]);
    }

    private function slotWalFor(NodeResources $resources): int
    {
        return max(self::MIN_SLOT_WAL_MB, intdiv($resources->diskGb * 1024, 4));
    }
}
