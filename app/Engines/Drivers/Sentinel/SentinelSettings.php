<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Data\EngineField;
use App\Engines\Data\Member;
use App\Engines\Settings\Setting;
use App\Engines\Settings\SettingsCatalog;

final readonly class SentinelSettings
{
    public const string PUBSUB_LIMIT = 'pubsub-output-buffer-limit';

    public const string PERSISTENCE = 'appendonly';

    private const int MAX_INT32 = 2147483647;

    private const int MAX_INT64 = 9223372036854775807;

    private const int MAX_UNSIGNED_32 = 4294967295;

    private const int OPEN_FILES_LIMIT = 65536;

    private const int RESERVED_FILES = 32;

    private const string MEMORY_PATTERN = '[0-9]{1,15}([bB]|[kK][bB]?|[mM][bB]?|[gG][bB]?)?';

    private const string KEYSPACE_PATTERN = '[KEglshzxeAtmdn]{0,32}';

    private const string LIVE_IO_THREADS_FROM = '9';

    private const array EVICTION_POLICIES = ['noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random', 'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl'];

    public static function catalogFor(SentinelFamilyEngine $engine, string $line): SettingsCatalog
    {
        $valkey = $engine->id === 'valkey';
        $threadsRestart = ! $valkey || version_compare($line, self::LIVE_IO_THREADS_FROM, '<');

        return new SettingsCatalog([
            Setting::choice(self::PERSISTENCE, 'Persistence', 'Keep data on disk with snapshots and an append-only file. no keeps data in memory only.', ['yes', 'no'], 'yes'),
            Setting::choice('maxmemory-policy', 'Eviction policy', 'What happens when memory is full. noeviction rejects writes; the others remove keys to make room.', self::EVICTION_POLICIES, 'noeviction'),
            Setting::text('maxmemory', 'Max memory', 'Memory the dataset may use, such as 1433mb or 4gb.', self::MEMORY_PATTERN, '0', tuned: true),
            Setting::integer('io-threads', 'I/O threads', 'Threads that read and write client sockets.', 1, $valkey ? 256 : 128, '1', restart: $threadsRestart, tuned: true),
            Setting::integer('maxclients', 'Max clients', 'Most client connections accepted at once.', 1, self::MAX_UNSIGNED_32, '10000', tuned: true),
            Setting::integer(self::PUBSUB_LIMIT, 'Pub/Sub output buffer', 'Hard output buffer limit for Pub/Sub clients. The soft limit is a quarter of it.', 1, self::MAX_INT32, '32', unit: 'MB'),
            Setting::integer('databases', 'Number of databases', 'Logical databases selectable with SELECT. Each one costs memory when the server starts.', 1, 65536, '16', restart: true),
            Setting::integer('lfu-log-factor', 'LFU log factor', 'Counter logarithm factor for the LFU eviction policies.', 0, self::MAX_INT32, '10'),
            Setting::integer('lfu-decay-time', 'LFU decay time', 'Minutes before an LFU counter is halved.', 0, self::MAX_INT32, '1', unit: 'min'),
            Setting::integer('timeout', 'Idle timeout', 'Seconds before an idle client is disconnected. 0 never disconnects.', 0, self::MAX_INT32, '0', unit: 's'),
            Setting::text('notify-keyspace-events', 'Keyspace notifications', 'Events published to keyspace channels, such as Ex for expired keys. Empty turns them off.', self::KEYSPACE_PATTERN, ''),
            Setting::choice('acl-pubsub-default', 'Default channel access', 'Pub/Sub channels new users may use when their ACL names none.', ['resetchannels', 'allchannels'], 'resetchannels'),
            Setting::integer('active-expire-effort', 'Active expire effort', 'Effort spent removing expired keys in the background.', 1, 10, '1'),
            Setting::integer('slowlog-log-slower-than', 'Slow log threshold', 'Commands slower than this many microseconds are logged. -1 turns the slow log off.', -1, self::MAX_INT64, '10000', unit: 'µs'),
            Setting::integer('slowlog-max-len', 'Slow log length', 'Number of slow commands kept.', 0, self::MAX_INT64, '128'),
        ], self::tunedFor(...));
    }

    /**
     * @return list<string>
     */
    public static function restartOnlyKeys(SentinelFamilyEngine $engine, string $line): array
    {
        $fields = array_filter(self::catalogFor($engine, $line)->fields, fn (EngineField $field): bool => $field->restart);

        return array_values(array_map(fn (EngineField $field): string => $field->key, $fields));
    }

    /**
     * @return array<string, string>
     */
    private static function tunedFor(Member $resources): array
    {
        return [
            'maxmemory' => intdiv(max($resources->memoryMb, 1024) * 70, 100).'mb',
            'io-threads' => (string) min(max(intdiv(max($resources->cpus, 1), 2), 1), 16),
            'maxclients' => (string) (self::OPEN_FILES_LIMIT - self::RESERVED_FILES),
        ];
    }
}
