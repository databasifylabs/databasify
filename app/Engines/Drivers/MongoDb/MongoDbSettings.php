<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Engines\Data\Member;
use App\Engines\Settings\Setting;
use App\Engines\Settings\SettingsCatalog;

final readonly class MongoDbSettings
{
    public const string CACHE_SIZE = 'cacheSizeGB';

    public const string CONNECTIONS = 'maxIncomingConnections';

    public const string SLOW_OPERATION = 'slowOpThresholdMs';

    public const string VERBOSITY = 'verbosity';

    public const string TRANSACTIONS = 'transactionLifetimeLimitSeconds';

    public const string READ_CONCERN = 'defaultReadConcern';

    public const string WRITE_CONCERN = 'defaultWriteConcern';

    private const int MAX_INT32 = 2147483647;

    private const int RESERVED_MEMORY_MB = 1024;

    private const float MIN_CACHE_GB = 0.25;

    private const int MIN_CONNECTIONS = 100;

    private const int MAX_CONNECTIONS = 20000;

    public static function catalog(): SettingsCatalog
    {
        return new SettingsCatalog([
            Setting::decimal(self::CACHE_SIZE, 'Cache size', 'Memory the WiredTiger cache may use, in gigabytes.', self::MIN_CACHE_GB, 10000, '0.25', tuned: true, unit: 'GB'),
            Setting::integer(self::CONNECTIONS, 'Max connections', 'Most client connections accepted at once.', 1, 1000000, '1000000', restart: true, tuned: true),
            Setting::choice(self::READ_CONCERN, 'Default read concern', 'Default consistency of reads that name no read concern.', ['local', 'available', 'majority'], 'local'),
            Setting::choice(self::WRITE_CONCERN, 'Default write concern', 'Acknowledgement writes wait for when they name no write concern.', ['majority', '1'], 'majority'),
            Setting::integer(self::TRANSACTIONS, 'Transaction lifetime', 'Seconds a multi-document transaction may stay open before it is aborted.', 1, self::MAX_INT32, '60', unit: 's'),
            Setting::integer(self::SLOW_OPERATION, 'Slow operation threshold', 'Operations slower than this are logged and profiled.', 0, self::MAX_INT32, '100', unit: 'ms'),
            Setting::integer(self::VERBOSITY, 'Log verbosity', 'Log message verbosity level.', 0, 5, '0'),
        ], self::tunedFor(...));
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::catalog()->fields as $field) {
            $defaults[$field->key] = $field->default;
        }

        return $defaults;
    }

    /**
     * @return array<string, string>
     */
    private static function tunedFor(Member $resources): array
    {
        $memory = max($resources->memoryMb, 1);

        return [
            self::CACHE_SIZE => number_format(max(self::MIN_CACHE_GB, ($memory - self::RESERVED_MEMORY_MB) / 2 / 1024), 2, '.', ''),
            self::CONNECTIONS => (string) min(max(intdiv($memory, 2), self::MIN_CONNECTIONS), self::MAX_CONNECTIONS),
        ];
    }
}
