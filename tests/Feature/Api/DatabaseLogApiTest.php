<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\ServerLog;

test('database logs are listed without output', function () {
    $node = DatabaseNode::factory()->create();
    $log = ServerLog::factory()->for($node->server)->create(['database_id' => $node->database_id]);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$node->database_id}/logs")
        ->assertOk()
        ->assertJsonPath('data.0.id', $log->id)
        ->assertJsonMissingPath('data.0.output');
});

test('a single database log shows its output', function () {
    $node = DatabaseNode::factory()->create();
    $log = ServerLog::factory()->for($node->server)->create(['database_id' => $node->database_id, 'output' => "creating cluster\n"]);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$node->database_id}/logs/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.output', "creating cluster\n");
});

test('a log of another database is not found', function () {
    $database = Database::factory()->create();
    $log = ServerLog::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/logs/{$log->id}")->assertNotFound();
});
