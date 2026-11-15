<?php

declare(strict_types=1);

use App\Enums\Servers\NetworkKind;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

function addServerOnWireguard(Project $project, string $name, array $network): TestResponse
{
    return test()->actingAs(User::factory()->create())
        ->post(route('servers.store', $project), [
            'name' => $name,
            'public_ip' => fake()->unique()->ipv4(),
            'ssh_port' => 22,
            'ssh_user' => 'root',
            ...$network,
        ]);
}

test('a new wireguard network takes the first free block and gives the server its first address', function () {
    Queue::fake([ProvisionServerJob::class]);
    $project = Project::factory()->create();
    Network::factory()->wireguard(0)->create();

    addServerOnWireguard($project, 'db-1', ['network' => 'wireguard'])->assertSessionHasNoErrors();

    $network = Network::query()->where('cidr', '100.64.1.0/24')->sole();
    expect($network->kind)->toBe(NetworkKind::Wireguard)
        ->and(Server::query()->where('name', 'db-1')->sole())
        ->network_id->toBe($network->id)
        ->private_ip->toBe('100.64.1.1');
});

test('a new wireguard network skips blocks that overlap the server subnets', function () {
    Queue::fake([ProvisionServerJob::class]);
    $server = Server::factory()->ready()->onNetwork(null)->create(['interface_subnets' => ['100.64.0.0/16']]);

    $this->actingAs(User::factory()->create())
        ->put(route('servers.network.update', [$server->project_id, $server]), ['network' => 'wireguard', 'network_block' => 24])
        ->assertSessionHasNoErrors();

    expect($server->refresh()->network->cidr)->toBe('100.65.0.0/24');
});

test('a wireguard network is refused when no block is free', function () {
    Queue::fake([ProvisionServerJob::class]);
    $project = Project::factory()->create();
    Network::factory()->create(['cidr' => '100.64.0.0/10']);

    addServerOnWireguard($project, 'db-1', ['network' => 'wireguard', 'network_block' => 16])
        ->assertSessionHasErrors(['network' => 'No free /16 block is left in 100.64.0.0/10. Choose a smaller block size.']);

    expect(Server::query()->where('name', 'db-1')->exists())->toBeFalse();
    Queue::assertNotPushed(ProvisionServerJob::class);
});

test('servers joining an existing wireguard network get the next free address', function () {
    Queue::fake([ProvisionServerJob::class]);
    $project = Project::factory()->create();
    $network = Network::factory()->wireguard(3)->for($project)->create();
    Server::factory()->for($project)->onNetwork($network)->create(['private_ip' => '100.64.3.1']);

    addServerOnWireguard($project, 'db-2', ['network' => $network->id])->assertSessionHasNoErrors();

    expect(Server::query()->where('name', 'db-2')->sole()->private_ip)->toBe('100.64.3.2');
});
