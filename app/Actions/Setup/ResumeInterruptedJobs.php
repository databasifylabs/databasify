<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\RedisQueue;

final readonly class ResumeInterruptedJobs
{
    public function __construct(private Factory $queues) {}

    public function handle(): int
    {
        $resumed = 0;

        foreach (config()->array('horizon.defaults') as $supervisor) {
            $queue = $this->queues->connection(is_array($supervisor) && is_string($supervisor['connection'] ?? null) ? $supervisor['connection'] : null);
            $names = is_array($supervisor) && is_array($supervisor['queue'] ?? null) ? $supervisor['queue'] : [];

            if (! $queue instanceof RedisQueue) {
                continue;
            }

            foreach (array_filter($names, is_string(...)) as $name) {
                $key = $queue->getQueue($name);
                $redis = $queue->getConnection();

                foreach (array_filter((array) $redis->zrange("{$key}:reserved", 0, -1), is_string(...)) as $payload) {
                    $redis->zadd("{$key}:reserved", 0, $payload);
                }

                $resumed += count((array) $queue->migrateExpiredJobs("{$key}:reserved", $key));
            }
        }

        return $resumed;
    }
}
