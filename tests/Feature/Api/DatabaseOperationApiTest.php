<?php

declare(strict_types=1);

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NodeStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Jobs\Databases\FailoverDatabaseJob;
use App\Jobs\Databases\RebootDatabaseJob;
use App\Jobs\Databases\RetuneDatabaseJob;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImageRegistry;
use Tests\Support\RecordingDriver;

test('a database with a standby fails over', function () {
    Queue::fake([FailoverDatabaseJob::class]);
    $database = Database::factory()->highlyAvailable()->create();
    DatabaseNode::factory()->for($database)->create();
    DatabaseNode::factory()->for($database)->replica()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/failover")
        ->assertAccepted()
        ->assertJsonPath('data.status', 'recovering');

    Queue::assertPushed(FailoverDatabaseJob::class);
});

test('failover without a standby is refused', function () {
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/failover")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['database' => 'Failover needs at least one standby node.']);
});

test('a database upgrades to its available image', function () {
    Queue::fake([UpgradeDatabaseJob::class]);
    $newer = new EngineImage('ghcr.io/databasifylabs/engine-postgres', 'sha256:'.str_repeat('b', 64), '18.2', '0.2.2');
    $database = Database::factory()->create(['available_image' => $newer]);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/upgrade")
        ->assertAccepted()
        ->assertJsonPath('data.status', 'upgrading')
        ->assertJsonPath('data.engine_version', '18.2');
});

test('checking for an upgrade records the newer image', function () {
    app()->instance(ImageRegistry::class, new FakeImageRegistry(['18.2' => '0.2.2', '18.1' => '0.2.2']));
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/upgrade-check")
        ->assertOk()
        ->assertJsonPath('data.available_version', '18.2');
});

test('a database is retuned', function () {
    Queue::fake([RetuneDatabaseJob::class]);
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/retune")
        ->assertAccepted()
        ->assertJsonPath('data.status', 'updating');
});

test('a healthy database is rebooted', function () {
    Queue::fake([RebootDatabaseJob::class]);
    $database = Database::factory()->highlyAvailable()->create();
    DatabaseNode::factory()->for($database)->create(['position' => 1, 'role' => NodeRole::Primary]);
    DatabaseNode::factory()->for($database)->create(['position' => 2, 'role' => NodeRole::Replica]);
    RecordingDriver::fake()->on('nodes.status', fn (DatabaseNode $node) => new NodeStatus($node->role, '18.1'));
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/reboot")
        ->assertAccepted()
        ->assertJsonPath('data.status', 'rebooting');
});

test('recovery is refused when no member answers', function () {
    $database = Database::factory()->highlyAvailable()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create(['position' => 1, 'role' => NodeRole::Primary]);
    DatabaseNode::factory()->for($database)->create(['position' => 2, 'role' => NodeRole::Replica]);
    RecordingDriver::fake()->on('nodes.status', fn () => new NodeStatus(NodeRole::Unknown, '18.1'));
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/recover", ['others_down' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['database' => 'orders has no reachable member to recover from.']);
});

test('a failed setup is retried', function () {
    Queue::fake([CreateDatabaseJob::class]);
    $database = Database::factory()->status(DatabaseStatus::Failed)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/retry-setup")
        ->assertAccepted()
        ->assertJsonPath('data.status', 'pending');
});

test('refreshing reads the database status now', function () {
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create(['position' => 1, 'role' => NodeRole::Primary]);
    RecordingDriver::fake()->on('nodes.status', fn () => new NodeStatus(NodeRole::Primary, '18.1'));
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/refresh")
        ->assertOk()
        ->assertJsonPath('data.nodes.0.engine_version', '18.1');
});

test('stopping a database cancels its running engine tasks', function () {
    $database = Database::factory()->status(DatabaseStatus::Scaling)->create();
    $node = DatabaseNode::factory()->for($database)->create(['task_key' => 'run.node.copy']);
    $this->ssh()->replyTo('databasify task stop', '{"key":"run.node.copy","kind":"copy","state":"cancelled","phase":"stopped"}');
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/stop")->assertOk();

    expect($node->refresh()->task_key)->toBeNull();
});

test('operations need databases:write', function () {
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->postJson("/api/v1/databases/{$database->id}/retune")->assertForbidden();
});
