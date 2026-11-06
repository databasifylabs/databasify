<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @param  list<string>  $zones
 */
function fakeCloudflareAccount(array $zones, string $status = 'active'): void
{
    Http::fake([
        'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => $status]]),
        'api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => array_map(fn (string $zone): array => ['id' => "id-{$zone}", 'name' => $zone], $zones),
            'result_info' => ['total_pages' => 1],
        ]),
    ]);
}

it('adds a cloudflare account after checking its token and imports its domains', function () {
    fakeCloudflareAccount(['example.com', 'example.org']);

    $this->post('/dns', ['name' => 'Cloudflare main', 'type' => 'cloudflare', 'credentials' => ['token' => ' cf-token ']])
        ->assertRedirect('/dns')
        ->assertSessionHasNoErrors();

    $provider = DnsProvider::query()->sole();
    expect($provider)->type->toBe('cloudflare')->credentials->toBe(['token' => 'cf-token'])
        ->and($provider->zones()->orderBy('name')->pluck('external_id', 'name')->all())->toBe(['example.com' => 'id-example.com', 'example.org' => 'id-example.org']);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/user/tokens/verify') && $request->hasHeader('Authorization', 'Bearer cf-token'));
});

it('refuses a token cloudflare does not accept and stores nothing', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'Invalid API Token']]], 401)]);

    $this->post('/dns', ['name' => 'Cloudflare main', 'type' => 'cloudflare', 'credentials' => ['token' => 'bad']])
        ->assertSessionHasErrors(['credentials' => 'Cloudflare answered 401. Invalid API Token']);

    expect(DnsProvider::query()->count())->toBe(0);
});

it('refuses an inactive token', function () {
    fakeCloudflareAccount(['example.com'], 'disabled');

    $this->post('/dns', ['name' => 'Cloudflare main', 'type' => 'cloudflare', 'credentials' => ['token' => 'cf-token']])
        ->assertSessionHasErrors('credentials');

    expect(DnsProvider::query()->count())->toBe(0);
});

it('validates the account fields', function (array $input, string $field) {
    Http::fake();
    DnsProvider::factory()->create(['name' => 'taken']);

    $this->post('/dns', [...['name' => 'Cloudflare main', 'type' => 'cloudflare', 'credentials' => ['token' => 'cf-token']], ...$input])
        ->assertSessionHasErrors($field);

    Http::assertNothingSent();
})->with([
    'missing name' => [['name' => ''], 'name'],
    'taken name' => [['name' => 'taken'], 'name'],
    'unknown type' => [['type' => 'route53-ish'], 'type'],
    'missing token' => [['credentials' => ['token' => '']], 'credentials.token'],
]);

it('refreshes the domains, forgetting removed ones unless a database uses them', function () {
    $provider = DnsProvider::factory()->create();
    $kept = DnsZone::factory()->for($provider, 'provider')->create(['name' => 'used.com']);
    DnsZone::factory()->for($provider, 'provider')->create(['name' => 'gone.com']);
    idleDatabase('valkey', 1, DatabaseMode::Single)->update(['dns_zone_id' => $kept->id]);
    fakeCloudflareAccount(['example.com']);

    $this->post("/dns/{$provider->id}/refresh")->assertRedirect()->assertSessionHasNoErrors();

    expect($provider->zones()->orderBy('name')->pluck('name')->all())->toBe(['example.com', 'used.com']);
});

it('refuses to remove an account while a database uses one of its domains', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    $database->update(['dns_zone_id' => $zone->id]);

    $this->delete("/dns/{$zone->provider->id}")->assertSessionHasErrors(['provider' => "cache uses a domain from {$zone->provider->name}, pick another domain for it first."]);
    $database->update(['dns_zone_id' => null]);
    $this->delete("/dns/{$zone->provider->id}")->assertSessionHasNoErrors();

    expect(DnsProvider::query()->count())->toBe(0)
        ->and(DnsZone::query()->count())->toBe(0);
});

it('lists the accounts with their domains and never sends the token', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->get('/dns')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('dns/index')
        ->where('providers.0.name', $zone->provider->name)
        ->where('providers.0.zones.0.name', 'example.com')
        ->where('providers.0.zones.0.databases_count', 0)
        ->missing('providers.0.credentials'));
    expect($this->get('/dns')->getContent())->not->toContain('cf-token');
});

it('still lists an account whose provider plugin was removed', function () {
    DnsZone::factory()->for(DnsProvider::factory()->create(['type' => 'example-dns']), 'provider')->create(['name' => 'example.com']);

    $this->get('/dns')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('providers.0.type.label', 'example-dns (not installed)')
        ->where('providers.0.type.icon', null)
        ->where('providers.0.zones.0.name', 'example.com'));
});

it('renames a dns account without asking cloudflare and keeps its token', function () {
    $provider = DnsProvider::factory()->create(['name' => 'Cloudflare']);
    Http::fake();

    $this->put("/dns/{$provider->id}", ['name' => 'Cloudflare main', 'credentials' => ['token' => '']])->assertSessionHasNoErrors();

    expect($provider->fresh())->name->toBe('Cloudflare main')->credentials->toBe(['token' => 'cf-token']);
    Http::assertNothingSent();
});

it('refuses a new token that cannot see a domain a database uses, then imports the domains of a good one', function () {
    $provider = DnsProvider::factory()->create(['name' => 'Cloudflare']);
    $zone = DnsZone::factory()->for($provider, 'provider')->create(['name' => 'example.com']);
    idleDatabase('mysql', 1, DatabaseMode::Single)->update(['dns_zone_id' => $zone->id]);
    $zones = fn (string ...$names): array => ['success' => true, 'result' => array_map(fn (string $name): array => ['id' => "id-{$name}", 'name' => $name], $names), 'result_info' => ['total_pages' => 1]];
    Http::fake([
        'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => 'active']]),
        'api.cloudflare.com/client/v4/zones?*' => Http::sequence()->push($zones('example.org'))->push($zones('example.com', 'example.net'))->push($zones('example.com', 'example.net')),
    ]);

    $this->put("/dns/{$provider->id}", ['name' => 'Cloudflare', 'credentials' => ['token' => 'narrow']])
        ->assertSessionHasErrors(['credentials' => 'The new credentials cannot see example.com, which databases use. Give them access to those domains first.']);
    expect($provider->fresh()?->credentials)->toBe(['token' => 'cf-token']);

    $this->put("/dns/{$provider->id}", ['name' => 'Cloudflare', 'credentials' => ['token' => 'wide']])->assertSessionHasNoErrors();
    expect($provider->fresh()?->credentials)->toBe(['token' => 'wide'])
        ->and($provider->zones()->orderBy('name')->pluck('name')->all())->toBe(['example.com', 'example.net']);
});
