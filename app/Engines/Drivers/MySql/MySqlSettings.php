<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySql;

use App\Engines\Data\Member;
use App\Engines\Drivers\MySqlFamily\FamilySettings;
use App\Engines\Settings\Setting;
use App\Engines\Settings\SettingsCatalog;

final readonly class MySqlSettings
{
    private const array SQL_MODES = ['ALLOW_INVALID_DATES', 'ANSI_QUOTES', 'ERROR_FOR_DIVISION_BY_ZERO', 'HIGH_NOT_PRECEDENCE', 'IGNORE_SPACE', 'NO_AUTO_VALUE_ON_ZERO', 'NO_BACKSLASH_ESCAPES', 'NO_DIR_IN_CREATE', 'NO_ENGINE_SUBSTITUTION', 'NO_UNSIGNED_SUBTRACTION', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'ONLY_FULL_GROUP_BY', 'PAD_CHAR_TO_FULL_LENGTH', 'PIPES_AS_CONCAT', 'REAL_AS_FLOAT', 'STRICT_ALL_TABLES', 'STRICT_TRANS_TABLES', 'TIME_TRUNCATE_FRACTIONAL', 'ANSI', 'TRADITIONAL'];

    public static function catalog(): SettingsCatalog
    {
        return new SettingsCatalog([
            Setting::integer('max_connections', 'Max connections', 'Most client connections accepted at once.', 1, 100000, '151', tuned: true),
            Setting::bytes('innodb_buffer_pool_size', 'Buffer pool size', 'Memory InnoDB uses to cache tables and indexes, such as 4096M or 8G.', 5242880, 9223372036854775807, '128M', tuned: true),
            Setting::bytes('innodb_log_buffer_size', 'Log buffer size', 'Memory that buffers redo log writes before they reach disk.', 262144, FamilySettings::MAX_UNSIGNED_32, '64M', tuned: true),
            Setting::bytes('innodb_redo_log_capacity', 'Redo log capacity', 'Disk space the redo log files may use, such as 1G.', 8388608, 549755813888, '100M', tuned: true),
            Setting::integer('innodb_read_io_threads', 'Read I/O threads', 'Background threads that read pages from disk.', 1, 64, '4', restart: true, tuned: true),
            Setting::integer('innodb_write_io_threads', 'Write I/O threads', 'Background threads that write pages to disk.', 1, 64, '4', restart: true, tuned: true),
            Setting::integer('innodb_purge_threads', 'Purge threads', 'Background threads that purge old row versions.', 1, 32, '1', restart: true, tuned: true),
            Setting::integer('innodb_page_cleaners', 'Page cleaners', 'Threads that flush dirty pages from the buffer pool.', 1, 64, '1', restart: true, tuned: true),
            Setting::integer('innodb_parallel_read_threads', 'Parallel read threads', 'Threads one clustered index read may use.', 1, 256, '4', tuned: true),
            Setting::integer('innodb_io_capacity', 'I/O capacity', 'I/O operations per second InnoDB background tasks may use.', 100, FamilySettings::MAX_UNSIGNED_32, '10000', tuned: true),
            Setting::integer('innodb_io_capacity_max', 'Max I/O capacity', 'Most I/O operations per second InnoDB may use when it falls behind.', 100, FamilySettings::MAX_UNSIGNED_32, '20000', tuned: true),
            Setting::integer('back_log', 'Connection backlog', 'Pending connection requests queued while the server is busy.', 0, 65535, '151', restart: true, tuned: true),
            Setting::integer('table_open_cache', 'Open table cache', 'Open tables cached for all threads.', 1, 524288, '4000', tuned: true),
            Setting::integer('innodb_open_files', 'InnoDB open files', 'Most .ibd files InnoDB keeps open at once.', 0, 2147483647, '4000', restart: true, tuned: true),
            Setting::integer('table_open_cache_instances', 'Open table cache instances', 'Partitions of the open table cache.', 1, 64, '16', restart: true, tuned: true),
            Setting::integer('thread_cache_size', 'Thread cache', 'Threads kept for reuse after a connection closes.', 0, 16384, '9', tuned: true),
            Setting::integer('open_files_limit', 'Open files limit', 'File descriptors the server may use.', 0, FamilySettings::MAX_UNSIGNED_32, '65536', restart: true, tuned: true),
            Setting::bytes('temptable_max_ram', 'TempTable memory', 'Memory the TempTable engine may use before it spills to disk, such as 1G.', 2097152, FamilySettings::MAX_UNSIGNED, '1G', tuned: true),
            Setting::choice('automatic_sp_privileges', 'Automatic routine privileges', 'Grant EXECUTE and ALTER ROUTINE to the creator of a stored routine.', FamilySettings::ON_OFF, 'ON'),
            Setting::integer('connect_timeout', 'Connect timeout', 'Seconds the server waits for a connect packet before rejecting the handshake.', 2, FamilySettings::YEAR_SECONDS, '10', unit: 's'),
            Setting::text('default_time_zone', 'Time zone', 'UTC offset such as +06:00, or SYSTEM.', FamilySettings::TIME_ZONE_PATTERN, 'SYSTEM', restart: true),
            Setting::integer('div_precision_increment', 'Division precision', 'Digits added to the scale of results of the / operator.', 0, 30, '4'),
            Setting::integer('eq_range_index_dive_limit', 'Index dive limit', 'Equality ranges above this count use index statistics instead of index dives.', 0, FamilySettings::MAX_UNSIGNED_32, '200'),
            Setting::integer('group_concat_max_len', 'GROUP_CONCAT length', 'Longest result GROUP_CONCAT returns.', 4, FamilySettings::MAX_UNSIGNED, '1024', unit: 'B'),
            Setting::choice('innodb_ft_enable_stopword', 'Full-text stopwords', 'Use a stopword list when building FULLTEXT indexes.', FamilySettings::ON_OFF, 'ON'),
            Setting::integer('innodb_ft_max_token_size', 'Full-text max word length', 'Longest word stored in an InnoDB FULLTEXT index.', 10, 84, '84', restart: true),
            Setting::integer('innodb_ft_min_token_size', 'Full-text min word length', 'Shortest word stored in an InnoDB FULLTEXT index.', 0, 16, '3', restart: true),
            Setting::integer('innodb_ft_num_word_optimize', 'Full-text optimize batch', 'Words processed by each OPTIMIZE TABLE pass on a FULLTEXT index.', 1000, 10000, '2000'),
            Setting::integer('innodb_ft_result_cache_limit', 'Full-text result cache', 'Memory one FULLTEXT query may use for its results.', 1000000, FamilySettings::MAX_UNSIGNED_32, '2000000000', unit: 'B'),
            Setting::text('innodb_ft_server_stopword_table', 'Full-text server stopwords', 'Stopword table for all InnoDB tables, as database/table.', FamilySettings::STOPWORD_PATTERN, ''),
            Setting::text('innodb_ft_user_stopword_table', 'Full-text user stopwords', 'Stopword table for new FULLTEXT indexes, as database/table.', FamilySettings::STOPWORD_PATTERN, ''),
            Setting::integer('innodb_lock_wait_timeout', 'Lock wait timeout', 'Seconds a transaction waits for a row lock before giving up.', 1, 1073741824, '50', unit: 's'),
            Setting::choice('innodb_optimize_fulltext_only', 'Optimize full-text only', 'Make OPTIMIZE TABLE only process FULLTEXT index data.', FamilySettings::ON_OFF, 'OFF'),
            Setting::choice('innodb_print_all_deadlocks', 'Log all deadlocks', 'Write every deadlock to the error log.', FamilySettings::ON_OFF, 'OFF'),
            Setting::choice('innodb_rollback_on_timeout', 'Roll back on lock timeout', 'Roll back the whole transaction instead of the last statement when a lock wait times out.', FamilySettings::ON_OFF, 'OFF', restart: true),
            Setting::integer('interactive_timeout', 'Interactive idle timeout', 'Seconds before an idle interactive session is closed.', 1, FamilySettings::YEAR_SECONDS, '28800', unit: 's'),
            Setting::choice('slow_query_log', 'Slow query log', 'Record queries that take longer than the slow query time.', FamilySettings::ON_OFF, 'OFF'),
            Setting::decimal('long_query_time', 'Slow query time', 'Seconds a query must take to be logged as slow.', 0, 4719230590769954816, '10', unit: 's'),
            Setting::bytes('max_allowed_packet', 'Max packet size', 'Largest single query or row.', 1024, 1073741824, '64M'),
            Setting::bytes('max_heap_table_size', 'Max memory table size', 'Largest user-created MEMORY table.', 16384, FamilySettings::MAX_UNSIGNED, '16M'),
            Setting::bytes('tmp_table_size', 'Temporary table size', 'Largest in-memory temporary table before it moves to disk.', 1024, FamilySettings::MAX_UNSIGNED, '16M'),
            Setting::integer('max_seeks_for_key', 'Max seeks for key', 'Most key seeks the optimizer assumes when looking rows up by key.', 1, FamilySettings::MAX_UNSIGNED, '18446744073709551615'),
            Setting::bytes('net_buffer_length', 'Network buffer length', 'Starting size of the connection and result buffers.', 1024, 1048576, '16384'),
            Setting::integer('net_read_timeout', 'Network read timeout', 'Seconds to wait for more data from a connection before aborting the read.', 1, FamilySettings::YEAR_SECONDS, '30', unit: 's'),
            Setting::integer('net_write_timeout', 'Network write timeout', 'Seconds to wait for a block to be written to a connection before aborting the write.', 1, FamilySettings::YEAR_SECONDS, '60', unit: 's'),
            Setting::bytes('sort_buffer_size', 'Sort buffer size', 'Memory each session may use for a sort.', 32768, FamilySettings::MAX_UNSIGNED, '262144'),
            Setting::integer('wait_timeout', 'Idle timeout', 'Seconds before an idle connection is closed.', 1, FamilySettings::YEAR_SECONDS, '28800', unit: 's'),
            Setting::integer('binlog_expire_logs_seconds', 'Binary log retention', 'Seconds binary logs are kept. At least a day, so point-in-time restore always reaches the last backup.', FamilySettings::DAY_SECONDS, FamilySettings::MAX_UNSIGNED_32, '604800', unit: 's'),
            Setting::choice('character_set_server', 'Character set', 'Default character set for new databases and tables.', ['utf8mb4', 'utf8mb3', 'latin1', 'ascii', 'binary'], 'utf8mb4'),
            Setting::text('collation_server', 'Collation', 'Default collation for new databases and tables.', FamilySettings::COLLATION_PATTERN, 'utf8mb4_0900_ai_ci'),
            Setting::flags('sql_mode', 'SQL mode', 'Which SQL rules the server enforces, such as strict inserts and full GROUP BY.', self::SQL_MODES, 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'),
            Setting::choice('end_markers_in_json', 'JSON end markers', 'Repeat the opening key as a comment at the end of JSON optimizer traces.', FamilySettings::ON_OFF, 'OFF'),
            Setting::integer('information_schema_stats_expiry', 'Statistics cache expiry', 'Seconds cached table statistics in INFORMATION_SCHEMA stay valid.', 0, FamilySettings::YEAR_SECONDS, '86400', unit: 's'),
            Setting::integer('innodb_change_buffer_max_size', 'Change buffer size', 'Share of the buffer pool the change buffer may use.', 0, 50, '25', unit: '%'),
            Setting::integer('innodb_flush_neighbors', 'Flush neighbors', 'Flush neighbouring dirty pages together: 0 off, 1 contiguous, 2 same extent.', 0, 2, '0'),
            Setting::integer('innodb_thread_concurrency', 'Thread concurrency', 'Most threads inside InnoDB at once. 0 is unlimited.', 0, 1000, '0'),
            Setting::bytes('innodb_online_alter_log_max_size', 'Online ALTER log size', 'Most temporary log space one online DDL operation may use.', 65536, FamilySettings::MAX_UNSIGNED, '128M'),
            Setting::choice('internal_tmp_mem_storage_engine', 'Internal temporary tables', 'Storage engine for in-memory internal temporary tables.', ['TempTable', 'MEMORY'], 'TempTable'),
            Setting::integer('max_execution_time', 'Max execution time', 'Milliseconds a SELECT may run before it is stopped. 0 never stops.', 0, FamilySettings::MAX_UNSIGNED, '0', unit: 'ms'),
            Setting::integer('optimizer_prune_level', 'Optimizer pruning', 'Let the optimizer skip unpromising plans: 0 off, 1 on.', 0, 1, '1'),
            Setting::integer('optimizer_search_depth', 'Optimizer search depth', 'How deep the optimizer searches join orders. 0 picks it automatically.', 0, 62, '62'),
            Setting::text('optimizer_switch', 'Optimizer switches', 'Comma-separated flag=on|off pairs. Empty keeps the server defaults.', FamilySettings::SWITCH_PATTERN, ''),
            Setting::choice('windowing_use_high_precision', 'Precise window functions', 'Compute window functions without losing precision.', FamilySettings::ON_OFF, 'ON'),
        ], self::tunedFor(...));
    }

    /**
     * @return array<string, string>
     */
    private static function tunedFor(Member $resources): array
    {
        $values = FamilySettings::innodbTuning($resources);
        $values['innodb_redo_log_capacity'] = FamilySettings::logCapacityMb($resources).'M';
        $values['innodb_page_cleaners'] = FamilySettings::cpuShare($resources, 2, 1, 64);
        $values['innodb_parallel_read_threads'] = FamilySettings::cpuShare($resources, 2, 1, 64);
        $values['temptable_max_ram'] = max(64, intdiv(max($resources->memoryMb, 1024) * 3, 100)).'M';

        return $values;
    }
}
