<?php

declare(strict_types=1);

use App\Jobs\Networks\JoinWireguardNetworkJob;
use App\Jobs\Networks\LeaveWireguardNetworkJob;
use App\Models\DatabaseNode;
use App\Models\Network;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('a server can join a private network when its address is assigned', function () {
    $server = Server::factory()->ready()->onNetwork(null)->create();
    $network = Network::factory()->for($server->project)->create(['cidr' => '10.10.0.0/16']);

    $this->actingAs(User::factory()->create())
        ->put(route('servers.network.update', [$server->project_id, $server]), ['network' => $network->id, 'private_ip' => '10.10.0.7'])
        ->assertSessionHasNoErrors();

    expect($server->refresh())
        ->network_id->toBe($network->id)
        ->private_ip->toBe('10.10.0.7')
        ->and($this->ssh()->ran("grep -qxF '10.10.0.7'", $server))->toBeTrue();
});

test('a private address that is not on the server is refused', function () {
    $server = Server::factory()->ready()->onNetwork(null)->create(['name' => 'db-1']);
    $network = Network::factory()->for($server->project)->create(['cidr' => '10.10.0.0/16']);
    $this->ssh()->replyTo("grep -qxF '10.10.0.7'", "10.10.0.7 is not assigned on this server.\n", exit: 1);

    $this->actingAs(User::factory()->create())
        ->put(route('servers.network.update', [$server->project_id, $server]), ['network' => $network->id, 'private_ip' => '10.10.0.7'])
        ->assertSessionHasErrors(['private_ip' => '10.10.0.7 is not assigned on db-1.']);

    expect($server->refresh()->network_id)->toBeNull();
});

test('a server of a database cannot change networks', function () {
    $node = DatabaseNode::factory()->create();
    $network = Network::factory()->create(['cidr' => '10.10.0.0/16']);

    $this->actingAs(User::factory()->create())
        ->put(route('servers.network.update', [$node->server->project_id, $node->server]), ['network' => $network->id, 'private_ip' => '10.10.0.7'])
        ->assertSessionHasErrors(['network' => "{$node->server->name} is part of a database, remove it from the database first."]);
});

test('a ready server moving to a wireguard network leaves the old one and joins the new one', function () {
    Queue::fake([LeaveWireguardNetworkJob::class, JoinWireguardNetworkJob::class]);
    $old = Network::factory()->wireguard(0)->create();
    $new = Network::factory()->wireguard(1)->create();
    $server = Server::factory()->ready()->onNetwork($old)->create(['private_ip' => '100.64.0.2']);

    $this->actingAs(User::factory()->create())
        ->put(route('servers.network.update', [$server->project_id, $server]), ['network' => $new->id])
        ->assertSessionHasNoErrors();

    expect($server->refresh())
        ->network_id->toBe($new->id)
        ->private_ip->toStartWith('100.64.1.');
    Queue::assertPushed(LeaveWireguardNetworkJob::class);
    Queue::assertPushed(JoinWireguardNetworkJob::class);
});
