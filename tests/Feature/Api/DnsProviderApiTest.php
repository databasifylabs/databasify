<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Project;
use Illuminate\Support\Facades\Http;

/**
 * @param  list<string>  $names
 * @return array<string, mixed>
 */
function cloudflareApiZones(array $names): array
{
    return [
        'result' => array_map(fn (string $name): array => ['id' => "zone-{$name}", 'name' => $name], $names),
        'result_info' => ['total_pages' => 1],
    ];
}

test('dns providers are listed with zones and without credentials', function () {
    $provider = DnsProvider::factory()->create(['name' => 'cf']);
    DnsZone::factory()->for($provider, 'provider')->create(['name' => 'example.com']);
    actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:read')->create());

    $this->getJson("/api/v1/projects/{$provider->project_id}/dns-providers")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'cf')
        ->assertJsonPath('data.0.zones.0.name', 'example.com')
        ->assertJsonMissingPath('data.0.credentials')
        ->assertJsonStructure(['data' => [['id', 'project_id', 'name', 'type', 'label', 'zones' => [['id', 'name', 'databases_count']]]]]);
});

test('a dns provider is added with its zones', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['result' => ['status' => 'active']]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response(cloudflareApiZones(['example.com'])),
    ]);
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

    $this->postJson("/api/v1/projects/{$project->id}/dns-providers", ['name' => 'cf', 'type' => 'cloudflare', 'credentials' => ['token' => 'cf-valid']])
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.zones.0.name', 'example.com');
});

test('refreshing zones returns the provider with its zones', function () {
    Http::fake(['https://api.cloudflare.com/client/v4/zones*' => Http::response(cloudflareApiZones(['example.com', 'new.dev']))]);
    $provider = DnsProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

    $this->postJson("/api/v1/dns-providers/{$provider->id}/refresh")
        ->assertOk()
        ->assertJsonCount(2, 'data.zones');
});

test('a refresh the provider refuses answers 422', function () {
    Http::fake(['https://api.cloudflare.com/client/v4/zones*' => Http::response(['errors' => [['message' => 'Invalid API Token']]], 401)]);
    $provider = DnsProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

    $this->postJson("/api/v1/dns-providers/{$provider->id}/refresh")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('provider');
});

test('a dns provider whose domain a database uses cannot be deleted', function () {
    $provider = DnsProvider::factory()->create(['name' => 'cf']);
    $zone = DnsZone::factory()->for($provider, 'provider')->create();
    Database::factory()->for($provider->project)->create(['name' => 'orders', 'dns_zone_id' => $zone->id]);
    actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

    $this->deleteJson("/api/v1/dns-providers/{$provider->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['provider' => 'orders uses a domain from cf, pick another domain for it first.']);
});

test('an unused dns provider is deleted', function () {
    $provider = DnsProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:write')->create());

    $this->deleteJson("/api/v1/dns-providers/{$provider->id}")->assertNoContent();

    $this->assertModelMissing($provider);
});

test('a dns provider in another project is not found for a limited key', function () {
    $provider = DnsProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:read')->limitedTo(Project::factory()->create())->create());

    $this->getJson("/api/v1/dns-providers/{$provider->id}")->assertNotFound();
});

test('dns types list the installed dns providers', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:read')->create());

    $this->getJson('/api/v1/dns-providers/types')
        ->assertOk()
        ->assertJsonFragment(['value' => 'cloudflare']);
});

test('changing dns providers needs dns-providers:write', function () {
    $provider = DnsProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('dns-providers:read')->create());

    $this->postJson("/api/v1/dns-providers/{$provider->id}/refresh")->assertForbidden();
});
