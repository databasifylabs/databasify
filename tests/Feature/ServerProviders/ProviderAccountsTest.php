<?php

declare(strict_types=1);

use App\Models\Network;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('connects an account only when the provider accepts the token', function () {
    Http::fake(['https://api.digitalocean.com/v2/account' => Http::sequence()
        ->push(['message' => 'Unable to authenticate you'], 401)
        ->push(['account' => ['uuid' => 'x']])]);

    $this->post('/providers', ['name' => 'do', 'type' => 'digitalocean', 'credentials' => ['token' => 'bad']])
        ->assertSessionHasErrors(['credentials' => 'DigitalOcean refused these credentials: DigitalOcean answered 401. Unable to authenticate you']);
    $this->post('/providers', ['name' => 'do', 'type' => 'digitalocean', 'credentials' => ['token' => ' good ']])
        ->assertSessionHasNoErrors();

    expect(ServerProvider::query()->sole())->name->toBe('do')->credentials->toBe(['token' => 'good']);
});

it('shows the add provider page with how to create each token', function () {
    $this->get('/providers/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('providers/create')
        ->where('types.0.value', 'digitalocean')
        ->where('types.0.form.0.name', 'token')
        ->where('types.0.form.0.type', 'password')
        ->where('types.0.instructions.0', 'Open [API → Tokens](https://cloud.digitalocean.com/account/api/tokens) and generate a new token.')
        ->where('types.0.instructions.1', fn (string $step) => str_contains($step, '`droplet:create`') && str_contains($step, '`vpc:read`')));
});

it('returns to the providers list after adding an account', function () {
    Http::fake(['https://api.digitalocean.com/v2/account' => Http::response(['account' => ['uuid' => 'x']])]);

    $this->post('/providers', ['name' => 'Production', 'type' => 'digitalocean', 'credentials' => ['token' => 'good']])
        ->assertRedirect('/providers');
});

it('validates the account form', function () {
    $this->post('/providers', ['name' => '', 'type' => 'oracle'])->assertSessionHasErrors(['name', 'type']);
    $this->post('/providers', ['name' => 'do', 'type' => 'digitalocean'])->assertSessionHasErrors('credentials.token');
});

it('lists accounts and networks without credentials', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);
    Network::factory()->create(['name' => 'default-blr1', 'server_provider_id' => $provider->id, 'external_id' => 'vpc-1']);

    $this->get('/providers')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('providers/index')
        ->where('providers.0.name', 'do')
        ->where('providers.0.type.label', 'DigitalOcean')
        ->missing('providers.0.credentials')
        ->where('networks', fn ($networks) => collect($networks)->pluck('name')->contains('default-blr1')));
});

it('keeps accounts and networks that servers still use', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);
    $network = Network::factory()->create(['name' => 'office']);
    Server::factory()->create(['server_provider_id' => $provider->id, 'network_id' => $network->id]);

    $this->delete("/providers/{$provider->id}")->assertSessionHasErrors(['provider' => 'do still has 1 server in Databasify. Delete them first.']);
    $this->delete("/networks/{$network->id}")->assertSessionHasErrors(['network' => 'office still has servers on it.']);

    Server::query()->delete();
    $this->delete("/providers/{$provider->id}")->assertSessionHasNoErrors();
    $this->delete("/networks/{$network->id}")->assertSessionHasNoErrors();
    expect(ServerProvider::query()->exists())->toBeFalse()->and(Network::query()->whereKey($network->id)->exists())->toBeFalse();
});

it('serves regions, plans and networks of an account as json', function () {
    $provider = ServerProvider::factory()->create();
    Http::fake([
        'https://api.digitalocean.com/v2/regions*' => Http::response(['regions' => [['slug' => 'blr1', 'name' => 'Bangalore 1', 'available' => true]]]),
        'https://api.digitalocean.com/v2/vpcs*' => Http::response(['message' => 'rate limited'], 429),
    ]);

    $this->getJson("/providers/{$provider->id}/regions")->assertOk()->assertExactJson([['id' => 'blr1', 'name' => 'Bangalore 1', 'country' => 'IN']]);
    $this->getJson("/providers/{$provider->id}/regions/blr1/networks")->assertStatus(422)->assertJson(['message' => 'DigitalOcean answered 429. rate limited']);
});

it('answers unprocessable when the provider cannot be reached', function () {
    $provider = ServerProvider::factory()->create();
    Http::fake(['https://api.digitalocean.com/v2/regions*' => Http::failedConnection()]);

    $this->getJson("/providers/{$provider->id}/regions")->assertStatus(422)->assertJsonStructure(['message']);
});

it('shows which provider each server came from', function () {
    $provider = ServerProvider::factory()->create(['name' => 'production']);
    Server::factory()->ready()->create(['name' => 'manual-1']);
    Server::factory()->ready()->create(['name' => 'cloud-1', 'server_provider_id' => $provider->id, 'region' => 'blr1']);

    $this->get('/servers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('servers.data.0.name', 'cloud-1')
        ->where('servers.data.0.provider.name', 'production')
        ->where('servers.data.0.provider.type.value', 'digitalocean')
        ->where('servers.data.0.region', 'blr1')
        ->where('servers.data.1.name', 'manual-1')
        ->where('servers.data.1.provider', null));
});

it('deletes a provider network at the provider only when asked', function (string $type, string $url) {
    $provider = ServerProvider::factory()->create(['type' => $type]);
    $kept = Network::factory()->create(['server_provider_id' => $provider->id, 'external_id' => '11']);
    $removed = Network::factory()->create(['server_provider_id' => $provider->id, 'external_id' => '12']);
    Http::fake([$url.'*' => Http::response(null, 204)]);

    $this->delete("/networks/{$kept->id}")->assertSessionHasNoErrors();
    $this->delete("/networks/{$removed->id}", ['destroy_at_provider' => true])->assertSessionHasNoErrors();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === $url.'12');
    expect(Network::query()->whereKey([$kept->id, $removed->id])->exists())->toBeFalse();
})->with([
    'digitalocean' => ['digitalocean', 'https://api.digitalocean.com/v2/vpcs/'],
    'hetzner' => ['hetzner', 'https://api.hetzner.cloud/v1/networks/'],
    'vultr' => ['vultr', 'https://api.vultr.com/v2/vpcs/'],
]);

it('keeps the network when the provider refuses to delete it', function () {
    $provider = ServerProvider::factory()->create();
    $network = Network::factory()->create(['name' => 'default-blr1', 'server_provider_id' => $provider->id, 'external_id' => 'vpc-1']);
    Http::fake(['https://api.digitalocean.com/v2/vpcs/vpc-1' => Http::response(['message' => 'Cannot delete the default VPC'], 403)]);

    $this->delete("/networks/{$network->id}", ['destroy_at_provider' => true])
        ->assertSessionHasErrors(['network' => 'Could not delete default-blr1 at DigitalOcean: DigitalOcean answered 403. Cannot delete the default VPC']);
    expect($network->fresh())->not->toBeNull();
});

it('tells the add server page where each network comes from', function () {
    $provider = ServerProvider::factory()->create(['name' => 'production']);
    Network::factory()->create(['name' => 'a-vpc', 'cidr' => '10.104.0.0/20', 'server_provider_id' => $provider->id, 'external_id' => 'vpc-1', 'region' => 'blr1']);
    Network::factory()->create(['name' => 'b-office', 'cidr' => '10.0.1.0/24']);

    $this->get('/servers/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('networks.0.name', 'a-vpc')
        ->where('networks.0.provider_type', 'digitalocean')
        ->where('networks.0.provider_label', 'DigitalOcean')
        ->where('networks.0.provider', 'production')
        ->where('networks.0.region', 'blr1')
        ->where('networks.1.name', 'b-office')
        ->where('networks.1.provider_type', null));
});

it('renames an account without asking the provider and keeps its token', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);
    Http::fake();

    $this->put("/providers/{$provider->id}", ['name' => 'Production', 'credentials' => ['token' => '']])->assertSessionHasNoErrors();

    expect($provider->fresh())->name->toBe('Production')->credentials->toBe(['token' => 'test-token']);
    Http::assertNothingSent();
});

it('rotates the token only when the new one still sees every server of the account', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);
    Server::factory()->for($provider, 'serverProvider')->create(['name' => 'web-1', 'provider_server_id' => '101']);
    Http::fake([
        'https://api.digitalocean.com/v2/account' => Http::response(['account' => ['uuid' => 'x']]),
        'https://api.digitalocean.com/v2/droplets/101' => Http::sequence()
            ->push(['id' => 'not_found', 'message' => 'The resource you were accessing could not be found.'], 404)
            ->push(['droplet' => ['id' => 101, 'status' => 'active', 'networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.5']]]]]),
    ]);

    $this->put("/providers/{$provider->id}", ['name' => 'do', 'credentials' => ['token' => 'other-account']])
        ->assertSessionHasErrors(['credentials' => 'These credentials cannot see web-1. Use credentials for the same account.']);
    expect($provider->fresh()?->credentials)->toBe(['token' => 'test-token']);

    $this->put("/providers/{$provider->id}", ['name' => 'do', 'credentials' => ['token' => ' rotated ']])->assertSessionHasNoErrors();
    expect($provider->fresh()?->credentials)->toBe(['token' => 'rotated']);
});

it('answers unprocessable with the message when a provider call fails', function () {
    $provider = ServerProvider::factory()->create();
    Http::fake(['https://api.digitalocean.com/v2/regions*' => Http::response(['message' => 'down'], 503)]);

    $this->getJson("/providers/{$provider->id}/regions")->assertStatus(422)->assertJson(['message' => 'DigitalOcean answered 503. down']);
});
