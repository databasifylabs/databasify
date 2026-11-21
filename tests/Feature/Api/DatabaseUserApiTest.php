<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use Tests\Support\RecordingDriver;

test('the users list has no passwords', function () {
    $database = Database::factory()->create();
    $database->users()->create(['name' => 'reporting', 'password' => 'never-listed']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $response = $this->getJson("/api/v1/databases/{$database->id}/users")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'reporting')
        ->assertJsonMissingPath('data.0.password');

    expect($response->getContent())->not->toContain('never-listed');
});

test('credentials need databases:write', function () {
    $database = Database::factory()->create();
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'secret']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/users/{$user->id}/credentials")->assertForbidden();
});

test('credentials show the password to a write key', function () {
    $database = Database::factory()->create();
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'shown-to-writers']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->getJson("/api/v1/databases/{$database->id}/users/{$user->id}/credentials")
        ->assertOk()
        ->assertJsonPath('data.password', 'shown-to-writers');
});

test('creating a user returns its password once', function () {
    RecordingDriver::fake();
    $database = Database::factory()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();
    $database->recordDefaultAccounts();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $response = $this->postJson("/api/v1/databases/{$database->id}/users", ['name' => 'reporting'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'reporting');

    expect($response->json('data.password'))->toBe($database->users()->where('name', 'reporting')->sole()->password);
});

test('resetting a password returns the new one', function () {
    RecordingDriver::fake();
    $database = Database::factory()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();
    $database->recordDefaultAccounts();
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'old-password']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $response = $this->postJson("/api/v1/databases/{$database->id}/users/{$user->id}/password")->assertOk();

    expect($response->json('data.password'))->not->toBe('old-password')->toBe($user->refresh()->password);
});

test('the default user cannot be deleted', function () {
    RecordingDriver::fake();
    $database = Database::factory()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();
    $database->recordDefaultAccounts();
    $default = $database->users()->where('name', 'databasify')->sole();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$database->id}/users/{$default->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user' => 'databasify is the default user and can\'t be deleted.']);
});

test('a user of another database is not found', function () {
    $database = Database::factory()->create();
    $foreign = Database::factory()->create()->users()->create(['name' => 'reporting', 'password' => 'secret']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$database->id}/users/{$foreign->id}")->assertNotFound();
});
