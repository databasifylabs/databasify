<?php

declare(strict_types=1);

use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('providers page is displayed', function () {
    $provider = ServerProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('providers.index', $provider->project_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('providers/index')->has('providers', 1));
});

test('add provider page lists the provider types', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('providers.create', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('providers/create')->has('types'));
});

test('a provider is added once its credentials are verified', function () {
    Http::fake(['https://api.digitalocean.com/v2/account' => Http::response(['account' => ['status' => 'active']])]);
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('providers.store', $project), ['name' => 'do', 'type' => 'digitalocean', 'credentials' => ['token' => 'dop_v1_valid']])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('providers.index', $project));

    expect(ServerProvider::query()->sole())
        ->project_id->toBe($project->id)
        ->credentials->toBe(['token' => 'dop_v1_valid']);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer dop_v1_valid'));
});

test('a provider with refused credentials is not added', function () {
    Http::fake(['https://api.digitalocean.com/v2/account' => Http::response(['message' => 'Unable to authenticate you'], 401)]);
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('providers.store', $project), ['name' => 'do', 'type' => 'digitalocean', 'credentials' => ['token' => 'dop_v1_bad']])
        ->assertSessionHasErrors(['credentials' => 'DigitalOcean refused these credentials: DigitalOcean answered 401. Unable to authenticate you']);

    expect(ServerProvider::query()->exists())->toBeFalse();
});

test('adding a provider requires a name, a known type and its credentials', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('providers.store', $project), ['type' => 'nowhere'])
        ->assertSessionHasErrors(['name', 'type']);

    $this->actingAs($user)
        ->post(route('providers.store', $project), ['name' => 'do', 'type' => 'digitalocean'])
        ->assertSessionHasErrors('credentials.token');

    Http::assertNothingSent();
});

test('a provider can be renamed without checking its credentials again', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);

    $this->actingAs(User::factory()->create())
        ->put(route('providers.update', [$provider->project_id, $provider]), ['name' => 'digitalocean'])
        ->assertSessionHasNoErrors();

    expect($provider->refresh())
        ->name->toBe('digitalocean')
        ->credentials->toBe(['token' => 'test-token']);
    Http::assertNothingSent();
});

test('new credentials must see the servers already ordered with the provider', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account' => Http::response(['account' => []]),
        'https://api.digitalocean.com/v2/droplets/101' => Http::response(['id' => 'not_found', 'message' => 'The resource you were accessing could not be found.'], 404),
    ]);
    $provider = ServerProvider::factory()->create();
    Server::factory()->for($provider->project)->create(['name' => 'db-1', 'server_provider_id' => $provider->id, 'provider_server_id' => '101']);

    $this->actingAs(User::factory()->create())
        ->put(route('providers.update', [$provider->project_id, $provider]), ['name' => $provider->name, 'credentials' => ['token' => 'dop_v1_other_account']])
        ->assertSessionHasErrors(['credentials' => 'These credentials cannot see db-1. Use credentials for the same account.']);

    expect($provider->refresh()->credentials)->toBe(['token' => 'test-token']);
});

test('an unused provider can be deleted', function () {
    $provider = ServerProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('providers.destroy', [$provider->project_id, $provider]))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($provider);
});

test('a provider with servers or networks cannot be deleted', function () {
    $provider = ServerProvider::factory()->create(['name' => 'do']);
    Server::factory()->for($provider->project)->create(['server_provider_id' => $provider->id]);
    Network::factory()->for($provider->project)->create(['server_provider_id' => $provider->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('providers.destroy', [$provider->project_id, $provider]))
        ->assertSessionHasErrors(['provider' => 'do still has 1 server and 1 network in Databasify. Delete them first.']);

    $this->assertModelExists($provider);
});
