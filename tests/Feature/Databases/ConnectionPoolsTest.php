<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\PoolMode;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use App\Jobs\Databases\ApplyNodeConnectionPoolsJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function databaseWithSchema(array $attributes = []): Database
{
    $database = Database::factory()->create($attributes);
    $database->schemas()->create(['name' => 'shop']);

    return $database;
}

test('a connection pool is created and applied', function () {
    Queue::fake([ApplyConnectionPoolsJob::class]);
    $database = databaseWithSchema();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.pools.store', [$database->project_id, $database]), ['name' => 'shop_pool', 'schema' => 'shop', 'mode' => 'transaction', 'size' => 20])
        ->assertSessionHasNoErrors();

    expect($database->connectionPools()->sole())
        ->name->toBe('shop_pool')
        ->mode->toBe(PoolMode::Transaction)
        ->size->toBe(20)
        ->and($database->refresh()->status)->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(ApplyConnectionPoolsJob::class);
});

test('connection pools need a known schema, a free name and a sane size', function () {
    Queue::fake([ApplyConnectionPoolsJob::class]);
    $database = databaseWithSchema();
    $database->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'shop', 'mode' => PoolMode::Transaction, 'size' => 10]);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.pools.store', [$database->project_id, $database]), ['name' => 'shop_pool', 'schema' => 'missing', 'mode' => 'transaction', 'size' => 501])
        ->assertSessionHasErrors([
            'name' => 'The name has already been taken.',
            'schema' => 'The selected schema is invalid.',
            'size' => 'The size field must be between 1 and 500.',
        ]);

    Queue::assertNotPushed(ApplyConnectionPoolsJob::class);
});

test('engines without a pooler refuse connection pools', function () {
    Queue::fake([ApplyConnectionPoolsJob::class]);
    $database = databaseWithSchema(['engine' => 'mysql', 'version_line' => '8.4']);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.pools.store', [$database->project_id, $database]), ['name' => 'shop_pool', 'schema' => 'shop', 'mode' => 'transaction', 'size' => 20])
        ->assertSessionHasErrors(['name' => 'MySQL databases have no connection pools.']);

    expect($database->connectionPools()->exists())->toBeFalse();
});

test('a connection pool can be deleted', function () {
    Queue::fake([ApplyConnectionPoolsJob::class]);
    $database = databaseWithSchema();
    $pool = $database->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'shop', 'mode' => PoolMode::Transaction, 'size' => 10]);

    $this->actingAs(User::factory()->create())
        ->delete(route('databases.pools.destroy', [$database->project_id, $database, $pool]))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($pool);
    Queue::assertPushed(ApplyConnectionPoolsJob::class);
});

test('a read-only node can have its own pool with a name the database already uses', function () {
    Queue::fake([ApplyNodeConnectionPoolsJob::class]);
    $database = databaseWithSchema();
    $database->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'shop', 'mode' => PoolMode::Transaction, 'size' => 10]);
    $readOnly = DatabaseNode::factory()->for($database)->readOnly()->create(['name' => 'orders-reports']);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.nodes.pools.store', [$database->project_id, $database, $readOnly]), ['name' => 'shop_pool', 'schema' => 'shop', 'mode' => 'session', 'size' => 5])
        ->assertSessionHasNoErrors();

    expect($readOnly->connectionPools()->sole()->mode)->toBe(PoolMode::Session);
    Queue::assertPushed(ApplyNodeConnectionPoolsJob::class);
});
