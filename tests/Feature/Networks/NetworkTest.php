<?php

declare(strict_types=1);

use App\Jobs\Networks\CheckWireguardNetworkJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('an empty network can be deleted', function () {
    $network = Network::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('networks.destroy', [$network->project_id, $network]))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($network);
});

test('a network with servers cannot be deleted', function () {
    $network = Network::factory()->create(['name' => 'office']);
    Server::factory()->onNetwork($network)->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('networks.destroy', [$network->project_id, $network]))
        ->assertSessionHasErrors(['network' => 'office still has servers on it.']);

    $this->assertModelExists($network);
});

test('a provider network can be destroyed at the provider too', function () {
    Http::fake(['https://api.digitalocean.com/v2/vpcs/vpc-1' => Http::response(status: 204)]);
    $provider = ServerProvider::factory()->create();
    $network = Network::factory()->for($provider->project)->create(['server_provider_id' => $provider->id, 'external_id' => 'vpc-1']);

    $this->actingAs(User::factory()->create())
        ->delete(route('networks.destroy', [$network->project_id, $network]), ['destroy_at_provider' => true])
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($network);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'https://api.digitalocean.com/v2/vpcs/vpc-1');
});

test('a network the provider refuses to delete is kept', function () {
    Http::fake(['https://api.digitalocean.com/v2/vpcs/vpc-1' => Http::response(['message' => 'VPC is in use'], 409)]);
    $provider = ServerProvider::factory()->create();
    $network = Network::factory()->for($provider->project)->create(['name' => 'office', 'server_provider_id' => $provider->id, 'external_id' => 'vpc-1']);

    $this->actingAs(User::factory()->create())
        ->delete(route('networks.destroy', [$network->project_id, $network]), ['destroy_at_provider' => true])
        ->assertSessionHasErrors('network');

    $this->assertModelExists($network);
});

test('reconciling queues a check for each wireguard network with joined members', function () {
    Queue::fake([CheckWireguardNetworkJob::class]);
    $joined = Network::factory()->wireguard(0)->create();
    Server::factory()->onNetwork($joined)->create(['wireguard_public_key' => 'key=']);
    $waiting = Network::factory()->wireguard(1)->create();
    Server::factory()->onNetwork($waiting)->create();
    Network::factory()->create();

    $this->artisan('networks:reconcile')->expectsOutputToContain('Queued 1 network checks.')->assertSuccessful();

    Queue::assertPushed(CheckWireguardNetworkJob::class, 1);
    Queue::assertPushed(CheckWireguardNetworkJob::class, fn (CheckWireguardNetworkJob $job) => $job->network->is($joined));
});
