<?php

declare(strict_types=1);

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Events\MasterSupervisorDeployed;

it('puts the jobs a stopped Horizon left reserved back on their queue when Horizon starts', function () {
    config(['horizon.defaults' => ['test' => ['connection' => 'redis', 'queue' => ['resume-test']]]]);
    $queue = Queue::connection('redis');
    assert($queue instanceof RedisQueue);
    $redis = $queue->getConnection();
    $key = $queue->getQueue('resume-test');
    $redis->del($key, "{$key}:reserved", "{$key}:notify");
    $redis->zadd("{$key}:reserved", time() + 3900, '{"uuid":"held","displayName":"Held","job":"x","data":[],"attempts":1}');

    try {
        event(new MasterSupervisorDeployed('master'));

        expect($redis->zcard("{$key}:reserved"))->toBe(0)
            ->and($redis->llen($key))->toBe(1);
    } finally {
        $redis->del($key, "{$key}:reserved", "{$key}:notify");
    }
})->skip(fn (): bool => rescue(fn () => Redis::connection()->ping() === false, true, report: false), 'Redis is not running.');
