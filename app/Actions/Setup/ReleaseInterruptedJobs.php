<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\Enums\Queues\QueueGroup;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\JobPayload;
use RuntimeException;

final readonly class ReleaseInterruptedJobs
{
    private const string INTERRUPTED = 'Interrupted by an app restart.';

    /** @var list<string> */
    private const array LOCK_PREFIXES = ['laravel-queue-overlap:', 'laravel_unique_job:'];

    public function __construct(
        private Queue $queue,
        private Repository $cache,
        private JobRepository $horizonJobs,
    ) {}

    public function handle(): int
    {
        if (! $this->queue instanceof RedisQueue) {
            return 0;
        }

        $this->deleteStaleLocks();

        return collect(QueueGroup::cases())->sum(fn (QueueGroup $group): int => $this->releaseReservedOn($this->queue, $group->value));
    }

    private function releaseReservedOn(RedisQueue $queue, string $name): int
    {
        $redis = $queue->getConnection();
        $key = $queue->getQueue($name).':reserved';
        $payloads = array_filter((array) $redis->zrange($key, 0, -1), is_string(...));

        foreach ($payloads as $payload) {
            $redis->zrem($key, $payload);
            $this->horizonJobs->failed(new RuntimeException(self::INTERRUPTED), 'redis', $name, new JobPayload($payload));
        }

        return count($payloads);
    }

    private function deleteStaleLocks(): void
    {
        $store = $this->cache->getStore();

        if (! $store instanceof RedisStore) {
            return;
        }

        $redis = $store->lockConnection();
        $connectionPrefix = $this->prefixOf($redis);

        foreach (self::LOCK_PREFIXES as $lockPrefix) {
            foreach (array_filter((array) $redis->keys($store->getPrefix().$lockPrefix.'*'), is_string(...)) as $fullKey) {
                $key = substr($fullKey, strlen($connectionPrefix));

                if ($redis->ttl($key) === -1) {
                    $redis->del($key);
                }
            }
        }
    }

    private function prefixOf(Connection $redis): string
    {
        $prefix = config("database.redis.{$redis->getName()}.prefix") ?? config('database.redis.options.prefix');

        return is_string($prefix) ? $prefix : '';
    }
}
