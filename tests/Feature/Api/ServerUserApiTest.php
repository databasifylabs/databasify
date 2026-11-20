<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Server;
use App\Models\ServerUser;

test('server users are listed', function () {
    $server = Server::factory()->ready()->create();
    $server->users()->create(['username' => 'deploy']);
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson("/api/v1/servers/{$server->id}/users")
        ->assertOk()
        ->assertJsonPath('data.0.username', 'deploy');
});

test('a server user is created', function () {
    $server = Server::factory()->ready()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->postJson("/api/v1/servers/{$server->id}/users", ['username' => 'deploy', 'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBk deploy@laptop'])
        ->assertCreated()
        ->assertJsonPath('data.username', 'deploy');

    expect($this->ssh()->ran('useradd --create-home --shell /bin/bash deploy', $server))->toBeTrue();
});

test('root cannot be created as a server user', function () {
    $server = Server::factory()->ready()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->postJson("/api/v1/servers/{$server->id}/users", ['username' => 'root'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

test('a server user is deleted', function () {
    $server = Server::factory()->ready()->create();
    $user = $server->users()->create(['username' => 'deploy']);
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->deleteJson("/api/v1/servers/{$server->id}/users/{$user->id}")->assertNoContent();

    $this->assertModelMissing($user);
});

test('a user of another server is not found', function () {
    $server = Server::factory()->ready()->create();
    $otherUser = Server::factory()->ready()->create()->users()->create(['username' => 'deploy']);
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->deleteJson("/api/v1/servers/{$server->id}/users/{$otherUser->id}")->assertNotFound();

    expect(ServerUser::query()->whereKey($otherUser->id)->exists())->toBeTrue();
});
