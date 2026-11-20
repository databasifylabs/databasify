<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;

test('database metric history is read', function () {
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/metrics/history")
        ->assertOk()
        ->assertJsonPath('data.period', '1h');
});

test('metric history refuses unknown periods', function () {
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/metrics/history?period=1y")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period');
});

test('node logs are read from the engine', function () {
    $database = Database::factory()->create(['log_units' => ['postgres', 'patroni']]);
    $node = DatabaseNode::factory()->for($database)->create();
    $this->ssh()->replyTo('databasify logs read', json_encode(['units' => ['postgres'], 'lines' => ['ready to accept connections']]));
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/nodes/{$node->id}/logs?service=postgres&lines=50")
        ->assertOk()
        ->assertExactJson(['data' => ['logs' => 'ready to accept connections']]);
});

test('node logs are limited to the services the engine runs', function () {
    $database = Database::factory()->create(['log_units' => ['postgres']]);
    $node = DatabaseNode::factory()->for($database)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/nodes/{$node->id}/logs?service=sshd")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('service');
});

test('node metric history is read for that node', function () {
    $database = Database::factory()->create();
    $node = DatabaseNode::factory()->for($database)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/nodes/{$node->id}/metrics/history")
        ->assertOk()
        ->assertJsonPath('data.nodes.0.id', $node->id);
});
