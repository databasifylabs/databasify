<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Shared;

use App\DTOs\Databases\ConfigField;
use App\DTOs\Databases\NodeResources;
use Illuminate\Support\Number;

final readonly class MysqlFamily
{
    public const string BINLOG_RETENTION_SECONDS = '259200';

    public const string ENV_NOTE = "Laravel connects without TLS unless MYSQL_ATTR_SSL_CA is set. To encrypt without the CA, set 'options' => [Mysql::ATTR_SSL_CIPHER => 'DEFAULT', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false] on the connection in config/database.php. To verify the server, download the CA certificate and add MYSQL_ATTR_SSL_CA=/path/to/{ca}.";

    private const array SWITCH = ['ON', 'OFF'];

    private const string SIZE = '[0-9]{1,12}[KMG]?';

    private const string STOPWORD_TABLE = '([A-Za-z0-9_$]{1,64}/[A-Za-z0-9_$]{1,64})?';

    private const int OPEN_FILES_LIMIT = 65536;

    private const int OS_RESERVE_MB = 512;

    private const int CONNECTION_MB = 4;

    private const int MIN_CONNECTIONS = 151;

    private const int POOL_CHUNK_MB = 128;

    /**
     * @return list<ConfigField>
     */
    public static function fields(): array
    {
        return [
            ConfigField::integer('max_connections', 'Max connections', 'Most client connections accepted at once.', 10, 100000, '')->tunedFromServer(),
            ConfigField::text('innodb_buffer_pool_size', 'Buffer pool size', 'Memory InnoDB uses to cache tables and indexes, such as 4096M or 8G.', self::SIZE, '')->tunedFromServer(),
            ConfigField::text('innodb_log_buffer_size', 'Log buffer size', 'Memory that buffers redo log writes before they reach disk.', self::SIZE, '')->tunedFromServer()->restarting(),
            ConfigField::integer('innodb_read_io_threads', 'Read I/O threads', 'Background threads that read pages from disk.', 1, 64, '')->tunedFromServer()->restarting(),
            ConfigField::integer('innodb_write_io_threads', 'Write I/O threads', 'Background threads that write pages to disk.', 1, 64, '')->tunedFromServer()->restarting(),
            ConfigField::choice('automatic_sp_privileges', 'Automatic routine privileges', 'Grant EXECUTE and ALTER ROUTINE to the creator of a stored routine.', self::SWITCH, 'ON'),
            ConfigField::integer('connect_timeout', 'Connect timeout', 'Seconds the server waits for a connect packet before rejecting the handshake.', 2, 3600, '10', 's'),
            ConfigField::text('default_time_zone', 'Time zone', 'UTC offset such as +06:00, or SYSTEM.', '(SYSTEM|[+-](0[0-9]|1[0-3]):[0-5][0-9]|[+-]14:00)', 'SYSTEM')->restarting(),
            ConfigField::integer('div_precision_increment', 'Division precision', 'Digits added to the scale of results of the / operator.', 0, 30, '4'),
            ConfigField::integer('eq_range_index_dive_limit', 'Index dive limit', 'Equality ranges above this count use index statistics instead of index dives.', 0, 4294967295, '200'),
            ConfigField::integer('group_concat_max_len', 'GROUP_CONCAT length', 'Longest result GROUP_CONCAT returns.', 4, 4294967295, '1024', 'B'),
            ConfigField::choice('innodb_ft_enable_stopword', 'Full-text stopwords', 'Use a stopword list when building FULLTEXT indexes.', self::SWITCH, 'ON'),
            ConfigField::integer('innodb_ft_max_token_size', 'Full-text max word length', 'Longest word stored in an InnoDB FULLTEXT index.', 10, 84, '84')->restarting(),
            ConfigField::integer('innodb_ft_min_token_size', 'Full-text min word length', 'Shortest word stored in an InnoDB FULLTEXT index.', 0, 16, '3')->restarting(),
            ConfigField::integer('innodb_ft_num_word_optimize', 'Full-text optimize batch', 'Words processed by each OPTIMIZE TABLE pass on a FULLTEXT index.', 1000, 10000, '2000'),
            ConfigField::integer('innodb_ft_result_cache_limit', 'Full-text result cache', 'Memory one FULLTEXT query may use for its results.', 1000000, 4294967295, '2000000000', 'B'),
            ConfigField::text('innodb_ft_server_stopword_table', 'Full-text server stopwords', 'Stopword table for all InnoDB tables, as database/table.', self::STOPWORD_TABLE, ''),
            ConfigField::text('innodb_ft_user_stopword_table', 'Full-text user stopwords', 'Stopword table for new FULLTEXT indexes, as database/table.', self::STOPWORD_TABLE, ''),
            ConfigField::integer('innodb_lock_wait_timeout', 'Lock wait timeout', 'Seconds a transaction waits for a row lock before giving up.', 1, 3600, '50', 's'),
            ConfigField::choice('innodb_optimize_fulltext_only', 'Optimize full-text only', 'Make OPTIMIZE TABLE only process FULLTEXT index data.', self::SWITCH, 'OFF'),
            ConfigField::choice('innodb_print_all_deadlocks', 'Log all deadlocks', 'Write every deadlock to the error log.', self::SWITCH, 'OFF'),
            ConfigField::choice('innodb_rollback_on_timeout', 'Roll back on lock timeout', 'Roll back the whole transaction instead of the last statement when a lock wait times out.', self::SWITCH, 'OFF')->restarting(),
            ConfigField::integer('interactive_timeout', 'Interactive idle timeout', 'Seconds before an idle interactive session is closed.', 30, 604800, '28800', 's'),
            ConfigField::choice('slow_query_log', 'Slow query log', 'Record queries that take longer than the slow query time.', self::SWITCH, 'OFF'),
            ConfigField::decimal('long_query_time', 'Slow query time', 'Seconds a query must take to be logged as slow.', 0, 3600, '10', 's'),
            ConfigField::integer('max_allowed_packet', 'Max packet size', 'Largest single query or row.', 102400, 1073741824, '67108864', 'B'),
            ConfigField::integer('max_heap_table_size', 'Max memory table size', 'Largest user-created MEMORY table.', 1048576, 1073741824, '16777216', 'B'),
            ConfigField::integer('tmp_table_size', 'Temporary table size', 'Largest in-memory temporary table before it moves to disk.', 1048576, 1073741824, '16777216', 'B'),
            ConfigField::integer('max_seeks_for_key', 'Max seeks for key', 'Most key seeks the optimizer assumes when looking rows up by key.', 1, 4294967295, '4294967295'),
            ConfigField::integer('net_buffer_length', 'Network buffer length', 'Starting size of the connection and result buffers.', 1024, 1048576, '16384', 'B'),
            ConfigField::integer('net_read_timeout', 'Network read timeout', 'Seconds to wait for more data from a connection before aborting the read.', 1, 3600, '30', 's'),
            ConfigField::integer('net_write_timeout', 'Network write timeout', 'Seconds to wait for a block to be written to a connection before aborting the write.', 1, 3600, '60', 's'),
            ConfigField::integer('sort_buffer_size', 'Sort buffer size', 'Memory each session may use for a sort.', 32768, 1073741824, '262144', 'B'),
            ConfigField::integer('wait_timeout', 'Idle timeout', 'Seconds before an idle connection is closed.', 1, 31536000, '28800', 's'),
            ConfigField::integer('binlog_expire_logs_seconds', 'Binary log retention', 'Seconds binary logs are kept before they are purged.', 600, 2592000, self::BINLOG_RETENTION_SECONDS, 's'),
            ConfigField::choice('character_set_server', 'Character set', 'Default character set for new databases and tables.', ['utf8mb4', 'utf8mb3', 'latin1', 'ascii', 'binary'], 'utf8mb4'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function switch(): array
    {
        return self::SWITCH;
    }

    /**
     * @return array<string, string>
     */
    public static function innodb(NodeResources $resources): array
    {
        $connections = self::maxConnections($resources);

        return [
            'innodb_buffer_pool_size' => self::bufferPoolMb($resources).'M',
            'innodb_log_buffer_size' => ($resources->hasMemoryOf(8192) ? 64 : 16).'M',
            'innodb_read_io_threads' => (string) $resources->cpuShare(2, 4, 64),
            'innodb_write_io_threads' => (string) $resources->cpuShare(4, 4, 64),
            'innodb_purge_threads' => (string) $resources->cpuShare(4, 1, 32),
            'innodb_io_capacity' => '2000',
            'innodb_io_capacity_max' => '4000',
            'max_connections' => (string) $connections,
            'back_log' => (string) min($connections, 65535),
            'table_open_cache' => (string) max(4000, $connections),
            'innodb_open_files' => (string) max(4000, $connections),
            'table_open_cache_instances' => (string) $resources->cpuShare(1, 1, 64),
            'thread_cache_size' => (string) (8 + intdiv($connections, 100)),
            'open_files_limit' => (string) self::OPEN_FILES_LIMIT,
        ];
    }

    public static function logCapacityMb(NodeResources $resources): int
    {
        return (int) Number::clamp(intdiv(self::bufferPoolMb($resources), 4), 256, 16384);
    }

    private static function bufferPoolMb(NodeResources $resources): int
    {
        $percent = match (true) {
            $resources->hasMemoryOf(4096) => 65,
            $resources->hasMemoryOf(2048) => 50,
            default => 25,
        };

        return max(self::POOL_CHUNK_MB, intdiv($resources->memoryPercent($percent), self::POOL_CHUNK_MB) * self::POOL_CHUNK_MB);
    }

    private static function maxConnections(NodeResources $resources): int
    {
        return max(self::MIN_CONNECTIONS, intdiv($resources->memoryMb - self::bufferPoolMb($resources) - self::OS_RESERVE_MB, self::CONNECTION_MB));
    }
}
