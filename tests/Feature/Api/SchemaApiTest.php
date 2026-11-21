<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use Tests\Support\RecordingDriver;

test('a schema is created', function () {
    RecordingDriver::fake();
    $database = Database::factory()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();
    $database->recordDefaultAccounts();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/schemas", ['name' => 'analytics'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'analytics')
        ->assertJsonPath('data.is_default', false);
});

test('reserved schema names are refused', function () {
    RecordingDriver::fake();
    $database = Database::factory()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/schemas", ['name' => 'template1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'The selected name is invalid.']);
});

test('schemas are listed and deleted', function () {
    RecordingDriver::fake();
    $database = Database::factory()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();
    $schema = $database->schemas()->create(['name' => 'analytics']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->getJson("/api/v1/databases/{$database->id}/schemas")->assertOk()->assertJsonPath('data.0.name', 'analytics');
    $this->deleteJson("/api/v1/databases/{$database->id}/schemas/{$schema->id}")->assertNoContent();

    $this->assertModelMissing($schema);
});
