<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Engines\Data\Member;
use App\Engines\Settings\Setting;
use App\Engines\Settings\SettingsCatalog;

final readonly class PostgresSettings
{
    private const int MIN_CONNECTIONS = 100;

    private const int CONNECTION_MB = 32;

    private const int MIN_SLOT_WAL_MB = 1024;

    private const string RELOADS_AUTOVACUUM_SINCE = '18';

    public static function catalogFor(string $line): SettingsCatalog
    {
        $autovacuumRestarts = version_compare($line, self::RELOADS_AUTOVACUUM_SINCE, '<');

        return new SettingsCatalog([
            Setting::integer('max_connections', 'Max connections', 'Most client connections accepted at once.', 1, 262143, '100', restart: true, tuned: true),
            Setting::size('shared_buffers', 'Shared buffers', 'Memory PostgreSQL uses to cache data pages, such as 512MB.', 128, 8589934584, '128MB', restart: true, tuned: true),
            Setting::size('effective_cache_size', 'Effective cache size', 'Memory the planner assumes the OS and PostgreSQL use to cache data.', 8, 17179869176, '4GB', tuned: true),
            Setting::size('work_mem', 'Work memory', 'Memory each sort or hash step may use before spilling to disk.', 64, 2147483647, '4MB', tuned: true),
            Setting::size('maintenance_work_mem', 'Maintenance memory', 'Memory VACUUM, CREATE INDEX and similar commands may use.', 64, 2147483647, '64MB', tuned: true),
            Setting::integer('max_worker_processes', 'Max background workers', 'Most background worker processes.', 0, 262143, '8', restart: true, tuned: true),
            Setting::integer('max_parallel_workers', 'Max parallel workers', 'Most workers all parallel operations may use at once.', 0, 1024, '8', tuned: true),
            Setting::integer('max_parallel_workers_per_gather', 'Parallel workers per query', 'Workers one query may use for a parallel scan.', 0, 1024, '2', tuned: true),
            Setting::integer('max_parallel_maintenance_workers', 'Parallel maintenance workers', 'Workers one CREATE INDEX or VACUUM may use.', 0, 1024, '2', tuned: true),
            Setting::decimal('random_page_cost', 'Random page cost', 'Planner cost of a random page read. Lower suits SSDs.', 0, 1.79769e+308, '4', tuned: true),
            Setting::integer('effective_io_concurrency', 'I/O concurrency', 'Concurrent disk reads the planner expects the storage to handle.', 0, 1000, '16', tuned: true),
            Setting::text('max_slot_wal_keep_size', 'Slot WAL retention', 'WAL a replication slot may hold before the replica must re-clone, such as 4096MB. -1 is unlimited.', '-1|[0-9]{1,9}(MB|GB|TB)', '-1', tuned: true),
            Setting::integer('autovacuum_freeze_max_age', 'Autovacuum freeze age', 'Transaction age that forces a VACUUM to prevent wraparound.', 100000, 2000000000, '200000000', restart: true),
            Setting::integer('autovacuum_max_workers', 'Autovacuum workers', 'Most autovacuum processes running at once.', 1, 262143, '3', restart: $autovacuumRestarts),
            Setting::integer('autovacuum_naptime', 'Autovacuum naptime', 'Seconds between autovacuum runs on a database.', 1, 2147483, '60', unit: 's'),
            Setting::integer('autovacuum_vacuum_threshold', 'Vacuum threshold', 'Updated or deleted rows needed to trigger a VACUUM on a table.', 0, 2147483647, '50'),
            Setting::integer('autovacuum_analyze_threshold', 'Analyze threshold', 'Changed rows needed to trigger an ANALYZE on a table.', 0, 2147483647, '50'),
            Setting::decimal('autovacuum_vacuum_scale_factor', 'Vacuum scale factor', 'Share of a table added to the vacuum threshold.', 0, 100, '0.2'),
            Setting::decimal('autovacuum_analyze_scale_factor', 'Analyze scale factor', 'Share of a table added to the analyze threshold.', 0, 100, '0.1'),
            Setting::decimal('autovacuum_vacuum_cost_delay', 'Vacuum cost delay', 'Milliseconds autovacuum sleeps when it reaches its cost limit. -1 uses vacuum_cost_delay.', -1, 100, '2', unit: 'ms'),
            Setting::integer('autovacuum_vacuum_cost_limit', 'Vacuum cost limit', 'Cost that makes autovacuum sleep. -1 uses vacuum_cost_limit.', -1, 10000, '-1'),
            Setting::integer('bgwriter_delay', 'Background writer delay', 'Milliseconds between background writer rounds.', 10, 10000, '200', unit: 'ms'),
            Setting::size('bgwriter_flush_after', 'Background writer flush', 'Data written by the background writer before it asks the OS to flush, such as 512kB.', 0, 2048, '512kB'),
            Setting::integer('bgwriter_lru_maxpages', 'Background writer pages', 'Most pages the background writer writes per round.', 0, 1073741823, '100'),
            Setting::decimal('bgwriter_lru_multiplier', 'Background writer multiplier', 'How far ahead of demand the background writer cleans pages.', 0, 10, '2'),
            Setting::integer('deadlock_timeout', 'Deadlock timeout', 'Milliseconds to wait on a lock before checking for a deadlock.', 1, 2147483647, '1000', unit: 'ms'),
            Setting::choice('default_toast_compression', 'TOAST compression', 'Compression for large column values.', ['pglz', 'lz4'], 'pglz'),
            Setting::choice('default_transaction_isolation', 'Isolation level', 'Isolation level of new transactions.', ['read uncommitted', 'read committed', 'repeatable read', 'serializable'], 'read committed'),
            Setting::integer('idle_in_transaction_session_timeout', 'Idle transaction timeout', 'Milliseconds a session may sit idle inside a transaction before it is closed. 0 never closes.', 0, 2147483647, '0', unit: 'ms'),
            Setting::integer('statement_timeout', 'Statement timeout', 'Milliseconds a statement may run before it is cancelled. 0 never cancels.', 0, 2147483647, '0', unit: 'ms'),
            Setting::integer('lock_timeout', 'Lock timeout', 'Milliseconds a statement waits for a lock before giving up. 0 waits forever.', 0, 2147483647, '0', unit: 'ms'),
            Setting::choice('jit', 'JIT compilation', 'Compile parts of expensive queries to machine code.', ['on', 'off'], 'on'),
            Setting::integer('log_autovacuum_min_duration', 'Log slow autovacuum', 'Autovacuum runs slower than this many milliseconds are logged. -1 turns it off.', -1, 2147483647, '600000', unit: 'ms'),
            Setting::choice('log_error_verbosity', 'Error log detail', 'How much detail each logged message includes.', ['terse', 'default', 'verbose'], 'default'),
            Setting::integer('log_min_duration_statement', 'Slow query log', 'Statements slower than this many milliseconds are logged. -1 turns it off.', -1, 2147483647, '-1', unit: 'ms'),
            Setting::integer('max_files_per_process', 'Max files per process', 'Most files each server process may keep open.', 64, 2147483647, '1000', restart: true),
            Setting::integer('max_locks_per_transaction', 'Locks per transaction', 'Average object locks each transaction may hold.', 10, 2147483647, '64', restart: true),
            Setting::integer('max_pred_locks_per_transaction', 'Predicate locks per transaction', 'Average predicate locks each serializable transaction may hold.', 10, 2147483647, '64', restart: true),
            Setting::integer('max_prepared_transactions', 'Prepared transactions', 'Most transactions in the prepared state at once.', 0, 262143, '0', restart: true),
            Setting::integer('max_logical_replication_workers', 'Logical replication workers', 'Most logical replication workers.', 0, 262143, '4', restart: true),
            Setting::size('max_stack_depth', 'Max stack depth', 'Deepest execution stack a session may use, such as 2MB.', 100, 2147483647, '2MB'),
            Setting::integer('max_standby_archive_delay', 'Standby archive delay', 'Milliseconds a standby waits before cancelling queries that conflict with archived WAL. -1 waits forever.', -1, 2147483647, '30000', unit: 'ms'),
            Setting::integer('max_standby_streaming_delay', 'Standby streaming delay', 'Milliseconds a standby waits before cancelling queries that conflict with streamed WAL. -1 waits forever.', -1, 2147483647, '30000', unit: 'ms'),
            Setting::integer('temp_file_limit', 'Temporary file limit', 'Kilobytes of temporary files each process may use. -1 is unlimited.', -1, 2147483647, '-1', unit: 'kB'),
            Setting::text('timezone', 'Time zone', 'Default time zone, such as UTC or Asia/Dhaka.', '[A-Za-z_]{1,20}(/[A-Za-z_+-]{1,30}){0,2}', 'UTC'),
            Setting::integer('track_activity_query_size', 'Tracked query size', 'Bytes of each running query kept for pg_stat_activity.', 100, 1048576, '1024', restart: true, unit: 'B'),
            Setting::choice('track_commit_timestamp', 'Track commit time', 'Record the commit time of each transaction.', ['on', 'off'], 'off', restart: true),
            Setting::choice('track_functions', 'Track functions', 'Which function calls are counted in statistics.', ['none', 'pl', 'all'], 'none'),
            Setting::choice('track_io_timing', 'Track I/O timing', 'Time disk reads and writes in statistics.', ['on', 'off'], 'off'),
            Setting::choice('wal_level', 'WAL level', 'Logical writes the extra WAL that logical replication and continuous migrations from this database need.', ['replica', 'logical'], 'replica', restart: true),
            Setting::integer('wal_sender_timeout', 'WAL sender timeout', 'Milliseconds before an inactive replication connection is closed.', 0, 2147483647, '60000', unit: 'ms'),
            Setting::integer('wal_writer_delay', 'WAL writer delay', 'Milliseconds between WAL writer flushes.', 1, 10000, '200', unit: 'ms'),
        ], self::tunedFor(...));
    }

    /**
     * @return array<string, string>
     */
    private static function tunedFor(Member $resources): array
    {
        $memory = max($resources->memoryMb, 1024);
        $cpus = max($resources->cpus, 1);
        $sharedBuffers = intdiv($memory * 25, 100);
        $connections = max(self::MIN_CONNECTIONS, intdiv($memory, self::CONNECTION_MB));
        $halfCpus = (string) min(max(intdiv($cpus, 2), 1), 8);

        return [
            'max_connections' => (string) $connections,
            'shared_buffers' => "{$sharedBuffers}MB",
            'effective_cache_size' => intdiv($memory * 75, 100).'MB',
            'work_mem' => min(max(intdiv($memory - $sharedBuffers, $connections * 3), 4), 256).'MB',
            'maintenance_work_mem' => min(intdiv($memory, 16), 2048).'MB',
            'max_worker_processes' => (string) max(8, $cpus),
            'max_parallel_workers' => (string) max(2, $cpus),
            'max_parallel_workers_per_gather' => $halfCpus,
            'max_parallel_maintenance_workers' => $halfCpus,
            'random_page_cost' => '1.1',
            'effective_io_concurrency' => '200',
            'max_slot_wal_keep_size' => max(self::MIN_SLOT_WAL_MB, intdiv(max($resources->diskGb, 1) * 1024, 4)).'MB',
        ];
    }
}
