<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\Member;

final readonly class FamilySettings
{
    public const array ON_OFF = ['ON', 'OFF'];

    public const float MAX_UNSIGNED = 18446744073709551615;

    public const int MAX_UNSIGNED_32 = 4294967295;

    public const int YEAR_SECONDS = 31536000;

    public const int DAY_SECONDS = 86400;

    public const string STOPWORD_PATTERN = '([A-Za-z0-9_$]{1,64}/[A-Za-z0-9_$]{1,64})?';

    public const string TIME_ZONE_PATTERN = 'SYSTEM|[+-](0[0-9]|1[0-3]):[0-5][0-9]|[+-]14:00';

    public const string COLLATION_PATTERN = '(utf8mb4|utf8mb3|latin1|ascii)_[a-z0-9_]{1,40}|binary';

    public const string SWITCH_PATTERN = '([a-z_]{1,40}=(on|off|default)(,[a-z_]{1,40}=(on|off|default))*)?';

    private const int OS_RESERVE_MB = 512;

    private const int CONNECTION_MB = 4;

    private const int MIN_CONNECTIONS = 151;

    private const int POOL_CHUNK_MB = 128;

    private const int KERNEL_RESERVE_PERCENT = 8;

    /**
     * @return array<string, string>
     */
    public static function innodbTuning(Member $resources): array
    {
        $connections = max(self::MIN_CONNECTIONS, intdiv(max($resources->memoryMb, 1024) - self::bufferPoolMb($resources) - self::OS_RESERVE_MB, self::CONNECTION_MB));

        return [
            'innodb_buffer_pool_size' => self::bufferPoolMb($resources).'M',
            'innodb_log_buffer_size' => self::hasMemoryOf($resources, 8192) ? '64M' : '16M',
            'innodb_read_io_threads' => self::cpuShare($resources, 2, 4, 64),
            'innodb_write_io_threads' => self::cpuShare($resources, 4, 4, 64),
            'innodb_purge_threads' => self::cpuShare($resources, 4, 1, 32),
            'innodb_io_capacity' => '2000',
            'innodb_io_capacity_max' => '4000',
            'max_connections' => (string) $connections,
            'back_log' => (string) min($connections, 65535),
            'table_open_cache' => (string) max(4000, $connections),
            'innodb_open_files' => (string) max(4000, $connections),
            'table_open_cache_instances' => self::cpuShare($resources, 1, 1, 64),
            'thread_cache_size' => (string) (8 + intdiv($connections, 100)),
            'open_files_limit' => '65536',
        ];
    }

    public static function logCapacityMb(Member $resources): int
    {
        return min(max(intdiv(self::bufferPoolMb($resources), 4), 256), 16384);
    }

    public static function cpuShare(Member $resources, int $divisor, int $low, int $high): string
    {
        return (string) min(max(intdiv(max($resources->cpus, 1), $divisor), $low), $high);
    }

    private static function bufferPoolMb(Member $resources): int
    {
        $percent = match (true) {
            self::hasMemoryOf($resources, 4096) => 65,
            self::hasMemoryOf($resources, 2048) => 50,
            default => 25,
        };

        return max(self::POOL_CHUNK_MB, intdiv(intdiv(max($resources->memoryMb, 1024) * $percent, 100), self::POOL_CHUNK_MB) * self::POOL_CHUNK_MB);
    }

    private static function hasMemoryOf(Member $resources, int $megabytes): bool
    {
        return $resources->memoryMb >= intdiv($megabytes * (100 - self::KERNEL_RESERVE_PERCENT), 100);
    }
}
