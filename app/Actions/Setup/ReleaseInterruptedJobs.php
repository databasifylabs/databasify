<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\Enums\Queues\QueueGroup;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\RedisQueue;
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

        $store = $this->cache->getStore();

        if ($store instanceof RedisStore) {
            $locks = $store->lockConnection();
            $prefix = config("database.redis.{$locks->getName()}.prefix") ?? config('database.redis.options.prefix');
            $connectionPrefix = is_string($prefix) ? $prefix : '';

            foreach (self::LOCK_PREFIXES as $lockPrefix) {
                foreach (array_filter((array) $locks->keys($store->getPrefix().$lockPrefix.'*'), is_string(...)) as $fullKey) {
                    $key = substr($fullKey, strlen($connectionPrefix));

                    if ($locks->ttl($key) === -1) {
                        $locks->del($key);
                    }
                }
            }
        }

        $redis = $this->queue->getConnection();
        $released = 0;

        foreach (QueueGroup::cases() as $group) {
            $key = $this->queue->getQueue($group->value).':reserved';

            foreach (array_filter((array) $redis->zrange($key, 0, -1), is_string(...)) as $payload) {
                $redis->zrem($key, $payload);
                $this->horizonJobs->failed(new RuntimeException(self::INTERRUPTED), 'redis', $group->value, new JobPayload($payload));
                $released++;
            }
        }

        return $released;
    }
}
