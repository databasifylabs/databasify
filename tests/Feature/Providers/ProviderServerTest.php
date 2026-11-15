<?php

declare(strict_types=1);

use App\Enums\Servers\ServerStatus;
use App\Jobs\Servers\LaunchProviderServerJob;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('a server can be ordered from a provider', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    $provider = ServerProvider::factory()->create();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('providers.servers.store', $provider->project_id), [
            'server_provider_id' => $provider->id,
            'name' => 'db-1',
            'region' => 'nyc3',
            'plan' => 's-2vcpu-4gb',
            'os' => 'ubuntu-24.04',
        ]);

    $server = Server::query()->sole();
    $response->assertSessionHasNoErrors()->assertRedirect(route('servers.show', [$provider->project_id, $server]));
    expect($server)
        ->server_provider_id->toBe($provider->id)
        ->status->toBe(ServerStatus::Creating)
        ->ssh_user->toBe('root');
    Queue::assertPushed(LaunchProviderServerJob::class);
});

test('an operating system the provider does not offer is refused', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    $provider = ServerProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('providers.servers.store', $provider->project_id), [
            'server_provider_id' => $provider->id,
            'name' => 'db-1',
            'region' => 'nyc3',
            'plan' => 's-2vcpu-4gb',
            'os' => 'debian-12',
        ])
        ->assertSessionHasErrors('os');

    expect(Server::query()->exists())->toBeFalse();
    Queue::assertNotPushed(LaunchProviderServerJob::class);
});

test('ordering a server can create a provider network', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    Http::fake(['https://api.digitalocean.com/v2/vpcs' => Http::response(['vpc' => ['id' => 'vpc-9', 'name' => 'databasify-nyc3-abcd', 'ip_range' => '10.120.0.0/20', 'default' => false]], 201)]);
    $provider = ServerProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('providers.servers.store', $provider->project_id), [
            'server_provider_id' => $provider->id,
            'name' => 'db-1',
            'region' => 'nyc3',
            'plan' => 's-2vcpu-4gb',
            'os' => 'ubuntu-24.04',
            'network' => 'new',
        ])
        ->assertSessionHasNoErrors();

    $network = Network::query()->where('external_id', 'vpc-9')->sole();
    expect($network->cidr)->toBe('10.120.0.0/20')
        ->and(Server::query()->sole()->network_id)->toBe($network->id);
});

test('a provider network that left the region is refused', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    Http::fake(['https://api.digitalocean.com/v2/vpcs*' => Http::response(['vpcs' => []])]);
    $provider = ServerProvider::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('providers.servers.store', $provider->project_id), [
            'server_provider_id' => $provider->id,
            'name' => 'db-1',
            'region' => 'nyc3',
            'plan' => 's-2vcpu-4gb',
            'os' => 'ubuntu-24.04',
            'network' => 'vpc-gone',
        ])
        ->assertSessionHasErrors(['network' => 'That private network is not in nyc3 anymore.']);

    Queue::assertNotPushed(LaunchProviderServerJob::class);
});

test('a provider of another project cannot be used to order a server', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    $current = ServerProvider::factory()->create()->project;
    $foreign = ServerProvider::factory()->for(Project::factory())->create();

    $this->actingAs(User::factory()->create())
        ->post(route('providers.servers.store', $current), [
            'server_provider_id' => $foreign->id,
            'name' => 'db-1',
            'region' => 'nyc3',
            'plan' => 's-2vcpu-4gb',
            'os' => 'ubuntu-24.04',
        ])
        ->assertSessionHasErrors(['server_provider_id' => 'The selected server provider id is invalid.']);

    Queue::assertNotPushed(LaunchProviderServerJob::class);
});
