<?php

declare(strict_types=1);

use App\Models\Database;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function cloudflareZones(array $names): array
{
    return [
        'result' => array_map(fn (string $name) => ['id' => "zone-{$name}", 'name' => $name], $names),
        'result_info' => ['total_pages' => 1],
    ];
}

test('dns page is displayed', function () {
    $provider = DnsProvider::factory()->create();
    DnsZone::factory()->for($provider, 'provider')->create();

    $this->actingAs(User::factory()->create())
        ->get(route('dns.index', $provider->project_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dns/index')->has('providers', 1));
});

test('a dns provider is added with its zones', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['result' => ['status' => 'active']]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response(cloudflareZones(['example.com', 'example.org'])),
    ]);
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('dns.store', $project), ['name' => 'cf', 'type' => 'cloudflare', 'credentials' => ['token' => 'cf-valid']])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dns.index', $project));

    expect(DnsProvider::query()->sole()->zones()->pluck('name')->all())->toBe(['example.com', 'example.org']);
});

test('a dns provider with refused credentials is not added', function () {
    Http::fake(['https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['errors' => [['message' => 'Invalid API Token']]], 401)]);
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('dns.store', $project), ['name' => 'cf', 'type' => 'cloudflare', 'credentials' => ['token' => 'cf-bad']])
        ->assertSessionHasErrors('credentials');

    expect(DnsProvider::query()->exists())->toBeFalse();
});

test('refreshing zones adds new ones and drops unused ones that are gone', function () {
    Http::fake(['https://api.cloudflare.com/client/v4/zones*' => Http::response(cloudflareZones(['example.com', 'new.dev']))]);
    $provider = DnsProvider::factory()->create();
    DnsZone::factory()->for($provider, 'provider')->create(['name' => 'example.com']);
    $gone = DnsZone::factory()->for($provider, 'provider')->create(['name' => 'gone.net']);
    $goneButUsed = DnsZone::factory()->for($provider, 'provider')->create(['name' => 'used.net']);
    Database::factory()->for($provider->project)->create(['dns_zone_id' => $goneButUsed->id]);

    $this->actingAs(User::factory()->create())
        ->post(route('dns.refresh', [$provider->project_id, $provider]))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($gone);
    $this->assertModelExists($goneButUsed);
    expect($provider->zones()->pluck('name')->all())->toBe(['example.com', 'new.dev', 'used.net']);
});

test('new credentials must still see the zones databases use', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['result' => ['status' => 'active']]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response(cloudflareZones(['other.com'])),
    ]);
    $provider = DnsProvider::factory()->create();
    $zone = DnsZone::factory()->for($provider, 'provider')->create(['name' => 'example.com']);
    Database::factory()->for($provider->project)->create(['dns_zone_id' => $zone->id]);

    $this->actingAs(User::factory()->create())
        ->put(route('dns.update', [$provider->project_id, $provider]), ['name' => $provider->name, 'credentials' => ['token' => 'cf-other']])
        ->assertSessionHasErrors(['credentials' => 'The new credentials cannot see example.com, which databases use. Give them access to those domains first.']);

    expect($provider->refresh()->credentials)->toBe(['token' => 'cf-token']);
});

test('a dns provider whose domain a database uses cannot be deleted', function () {
    $provider = DnsProvider::factory()->create(['name' => 'cf']);
    $zone = DnsZone::factory()->for($provider, 'provider')->create();
    Database::factory()->for($provider->project)->create(['name' => 'orders', 'dns_zone_id' => $zone->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('dns.destroy', [$provider->project_id, $provider]))
        ->assertSessionHasErrors(['provider' => 'orders uses a domain from cf, pick another domain for it first.']);

    $this->assertModelExists($provider);
});

test('an unused dns provider can be deleted', function () {
    $provider = DnsProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('dns.destroy', [$provider->project_id, $provider]))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($provider);
});
