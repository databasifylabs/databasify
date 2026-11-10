<?php

declare(strict_types=1);

use App\Actions\Databases\Pools\CreateConnectionPool;
use App\DTOs\Databases\NewConnectionPool;
use App\Engines\EngineCatalog;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\PoolMode;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('records the pool and queues it onto the nodes under a new operation', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    Queue::fake([ApplyConnectionPoolsJob::class]);

    $pool = resolve(CreateConnectionPool::class)->handle($database, new NewConnectionPool('reports', 'app', PoolMode::Statement, 15));

    expect($pool->fresh())
        ->name->toBe('reports')
        ->schema->toBe('app')
        ->mode->toBe(PoolMode::Statement)
        ->size->toBe(15)
        ->and($database->fresh()->operation)->toBe('Add connection pool reports');
    Queue::assertPushed(ApplyConnectionPoolsJob::class, fn (ApplyConnectionPoolsJob $job): bool => $job->database->is($database));
});

it('refuses pools on engines without a pooler', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    Queue::fake([ApplyConnectionPoolsJob::class]);

    expect(fn () => resolve(CreateConnectionPool::class)->handle($database, new NewConnectionPool('reports', 'app', PoolMode::Transaction, 15)))
        ->toThrow(ValidationException::class, EngineCatalog::find($engine)->label.' databases have no connection pools.')
        ->and($database->connectionPools()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['mysql', 'mariadb', 'valkey', 'redis']);

it('refuses pools while the database is busy', function () {
    $database = newDatabase('postgres', 1, DatabaseMode::Single);
    Queue::fake([ApplyConnectionPoolsJob::class]);

    expect(fn () => resolve(CreateConnectionPool::class)->handle($database, new NewConnectionPool('reports', 'app', PoolMode::Transaction, 15)))
        ->toThrow(ValidationException::class, 'busy with Create PostgreSQL database')
        ->and($database->connectionPools()->count())->toBe(0);
    Queue::assertNothingPushed();
});
