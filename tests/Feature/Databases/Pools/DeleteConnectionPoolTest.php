<?php

declare(strict_types=1);

use App\Actions\Databases\Pools\DeleteConnectionPool;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\PoolMode;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('forgets the pool and queues the remaining pools onto the nodes', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $pool = $database->connectionPools()->create(['name' => 'reports', 'schema' => 'app', 'mode' => PoolMode::Transaction, 'size' => 10]);
    $database->connectionPools()->create(['name' => 'web', 'schema' => 'app', 'mode' => PoolMode::Session, 'size' => 30]);
    Queue::fake([ApplyConnectionPoolsJob::class]);

    resolve(DeleteConnectionPool::class)->handle($pool);

    expect($database->connectionPools()->pluck('name')->all())->toBe(['web'])
        ->and($database->fresh()->operation)->toBe('Remove connection pool reports');
    Queue::assertPushed(ApplyConnectionPoolsJob::class, fn (ApplyConnectionPoolsJob $job): bool => $job->database->is($database));
});

it('keeps the pool while the database is busy', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $pool = $database->connectionPools()->create(['name' => 'reports', 'schema' => 'app', 'mode' => PoolMode::Transaction, 'size' => 10]);
    $database->startOperation('Upgrade');
    Queue::fake([ApplyConnectionPoolsJob::class]);

    expect(fn () => resolve(DeleteConnectionPool::class)->handle($pool))->toThrow(ValidationException::class, 'busy with Upgrade')
        ->and($pool->fresh())->not->toBeNull();
    Queue::assertNothingPushed();
});
