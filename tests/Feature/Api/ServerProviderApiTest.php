<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerProvider;
use Illuminate\Support\Facades\Http;

test('server providers are listed without credentials', function () {
    ServerProvider::factory()->create(['name' => 'do']);
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

    $this->getJson('/api/v1/server-providers')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'do')
        ->assertJsonMissingPath('data.0.credentials')
        ->assertJsonStructure(['data' => [['id', 'project_id', 'name', 'type', 'label', 'servers_count', 'networks_count']], 'links', 'meta']);
});

test('a project lists only its own server providers', function () {
    $mine = ServerProvider::factory()->for(Project::factory())->create();
    ServerProvider::factory()->for(Project::factory())->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

    $this->getJson("/api/v1/projects/{$mine->project_id}/server-providers")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

test('a server provider is added to a project', function () {
    Http::fake(['https://api.digitalocean.com/v2/account' => Http::response(['account' => ['status' => 'active']])]);
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

    $this->postJson("/api/v1/projects/{$project->id}/server-providers", ['name' => 'do', 'type' => 'digitalocean', 'credentials' => ['token' => 'dop_v1_valid']])
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonMissingPath('data.credentials');
});

test('a provider in another project is not found for a limited key', function () {
    $provider = ServerProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->limitedTo(Project::factory()->create())->create());

    $this->putJson("/api/v1/server-providers/{$provider->id}", ['name' => 'renamed'])->assertNotFound();
});

test('renaming a server provider', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

    $this->putJson("/api/v1/server-providers/{$provider->id}", ['name' => 'digitalocean'])
        ->assertOk()
        ->assertJsonPath('data.name', 'digitalocean');
});

test('a server provider with servers cannot be deleted', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);
    Server::factory()->for($provider->project)->create(['server_provider_id' => $provider->id]);
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

    $this->deleteJson("/api/v1/server-providers/{$provider->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('provider');
});

test('an unused server provider is deleted', function () {
    $provider = ServerProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

    $this->deleteJson("/api/v1/server-providers/{$provider->id}")->assertNoContent();

    $this->assertModelMissing($provider);
});

test('regions come from the provider api', function () {
    Http::fake(['https://api.digitalocean.com/v2/regions*' => Http::response(['regions' => [
        ['slug' => 'ams3', 'name' => 'Amsterdam 3', 'available' => true],
    ]])]);
    $provider = ServerProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

    $this->getJson("/api/v1/server-providers/{$provider->id}/regions")
        ->assertOk()
        ->assertExactJson(['data' => [['id' => 'ams3', 'name' => 'Amsterdam 3', 'country' => 'NL']]]);
});

test('a provider api failure answers json', function () {
    Http::fake(['https://api.digitalocean.com/v2/regions*' => Http::response(['message' => 'down'], 500)]);
    $provider = ServerProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

    $response = $this->get("/api/v1/server-providers/{$provider->id}/regions");

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and($response->headers->get('Content-Type'))->toContain('application/json');
    $response->assertJsonStructure(['message']);
});

test('types list the installed server providers', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

    $this->getJson('/api/v1/server-providers/types')
        ->assertOk()
        ->assertJsonFragment(['value' => 'digitalocean']);
});

test('an empty network is deleted', function () {
    $network = Network::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

    $this->deleteJson("/api/v1/networks/{$network->id}")->assertNoContent();

    $this->assertModelMissing($network);
});

test('networks are listed', function () {
    $network = Network::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

    $this->getJson("/api/v1/projects/{$network->project_id}/networks")
        ->assertOk()
        ->assertJsonPath('data.0.id', $network->id)
        ->assertJsonStructure(['data' => [['id', 'project_id', 'name', 'kind', 'cidr', 'region', 'server_provider_id', 'servers_count']]]);
});

test('writing needs server-providers:write', function () {
    $provider = ServerProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:read')->create());

    $this->deleteJson("/api/v1/server-providers/{$provider->id}")->assertForbidden();
});
