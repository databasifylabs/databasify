<?php

declare(strict_types=1);

use App\Enums\Databases\PoolMode;
use App\Jobs\Databases\ApplyConnectionPoolsJob;
use App\Jobs\Databases\ApplyNodeConnectionPoolsJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Queue;

test('a connection pool is created and applied', function () {
    Queue::fake([ApplyConnectionPoolsJob::class]);
    $database = Database::factory()->create();
    $database->schemas()->create(['name' => 'shop']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/pools", ['name' => 'shop_pool', 'schema' => 'shop', 'mode' => 'transaction', 'size' => 20])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'updating');

    $this->getJson("/api/v1/databases/{$database->id}/pools")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'shop_pool')
        ->assertJsonPath('data.0.size', 20);
});

test('connection pools need a sane size', function () {
    $database = Database::factory()->create();
    $database->schemas()->create(['name' => 'shop']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/pools", ['name' => 'shop_pool', 'schema' => 'shop', 'mode' => 'transaction', 'size' => 501])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['size' => 'The size field must be between 1 and 500.']);
});

test('a connection pool is deleted', function () {
    Queue::fake([ApplyConnectionPoolsJob::class]);
    $database = Database::factory()->create();
    $database->schemas()->create(['name' => 'shop']);
    $pool = $database->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'shop', 'mode' => PoolMode::Transaction, 'size' => 10]);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$database->id}/pools/{$pool->id}")->assertAccepted();

    $this->assertModelMissing($pool);
});

test('a pool of another database is not found', function () {
    $database = Database::factory()->create();
    $other = Database::factory()->create();
    $other->schemas()->create(['name' => 'shop']);
    $pool = $other->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'shop', 'mode' => PoolMode::Transaction, 'size' => 10]);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$database->id}/pools/{$pool->id}")->assertNotFound();
});

test('a read-only node gets its own pool', function () {
    Queue::fake([ApplyNodeConnectionPoolsJob::class]);
    $database = Database::factory()->create();
    $database->schemas()->create(['name' => 'shop']);
    $readOnly = DatabaseNode::factory()->for($database)->readOnly()->create(['name' => 'orders-reports']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/nodes/{$readOnly->id}/pools", ['name' => 'shop_pool', 'schema' => 'shop', 'mode' => 'session', 'size' => 5])
        ->assertAccepted()
        ->assertJsonPath('data.id', $readOnly->id);

    $this->getJson("/api/v1/databases/{$database->id}/nodes/{$readOnly->id}/pools")
        ->assertOk()
        ->assertJsonPath('data.0.mode', 'session');
});
