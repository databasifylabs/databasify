<?php

declare(strict_types=1);

use App\Actions\Networks\CheckWireguardNetwork;
use App\Actions\Networks\CreateWireguardNetwork;
use App\Actions\Networks\JoinWireguardNetwork;
use App\Actions\Networks\LeaveWireguardNetwork;
use App\Actions\Networks\SyncWireguardNetwork;
use App\Enums\Servers\NetworkMemberStatus;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Networks\CheckWireguardNetworkJob;
use App\Jobs\Networks\SyncWireguardNetworkJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => inProject());

function meshOf(int $members): Network
{
    $network = Network::factory()->wireguard()->create();
    foreach (range(1, $members) as $host) {
        Server::factory()->ready()->onNetwork($network)->create([
            'name' => "node-{$host}",
            'public_ip' => "203.0.113.{$host}",
            'private_ip' => "100.64.0.{$host}",
            'wireguard_public_key' => "KEY-{$host}",
        ]);
    }

    return $network;
}

function statusOutput(int $now, array $handshakes): string
{
    return "interface=up\nnow={$now}\n".collect($handshakes)->map(fn (int $at, string $key): string => "peer={$key} {$at}")->implode("\n")."\n";
}

it('skips 100.64 blocks that overlap any existing network', function () {
    Network::factory()->create(['cidr' => '100.64.0.0/23']);
    Network::factory()->wireguard(2)->create();

    expect(resolve(CreateWireguardNetwork::class)->handle(24, collect()))->name->toBe('databasify-0')->cidr->toBe('100.64.3.0/24');
});

it('refuses to join when the server already routes the network range', function () {
    $network = meshOf(1);
    $ssh = fakeSsh()->respond("This server already routes 100.64.0.0/24 (100.64.0.0/10 dev tailscale0), pick another network.\n", 1);
    $server = Server::query()->sole();

    expect(fn () => resolve(JoinWireguardNetwork::class)->handle($server, $network))
        ->toThrow(RemoteScriptFailed::class);
    expect($ssh->scriptAt(0))->toContain("ip -4 route show match '100.64.0.1' | grep -v '^default' | grep -v 'dev databasify'");
});

it('never stores the same address twice on one network', function () {
    $network = meshOf(1);

    expect(fn () => Server::factory()->onNetwork($network)->create(['private_ip' => '100.64.0.1']))->toThrow(UniqueConstraintViolationException::class);
});

it('records which members took the new peer list and which could not be reached', function () {
    $network = meshOf(2);
    fakeSsh()->respond("1 peer\n")->respond("connection refused\n", 255);

    resolve(SyncWireguardNetwork::class)->handle($network);

    expect(Server::query()->where('name', 'node-1')->sole())->network_status->toBe(NetworkMemberStatus::Connected)->network_issue->toBeNull()
        ->and(Server::query()->where('name', 'node-2')->sole())->network_status->toBe(NetworkMemberStatus::Unreachable)->network_issue->toContain('exit code 255')->network_checked_at->not->toBeNull()
        ->and(new SyncWireguardNetworkJob($network))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});

it('marks members connected, degraded or unreachable from their handshakes', function () {
    Queue::fake([SyncWireguardNetworkJob::class]);
    $network = meshOf(3);
    $now = 1_800_000_000;
    $ssh = fakeSsh()
        ->respond(statusOutput($now, ['KEY-2' => $now - 30, 'KEY-3' => $now - 40]))
        ->respond(statusOutput($now, ['KEY-1' => $now - 30, 'KEY-3' => $now - 400]))
        ->respond("timed out\n", 255);

    resolve(CheckWireguardNetwork::class)->handle($network);

    $members = Server::query()->orderBy('id')->get();
    expect($members[0])->network_status->toBe(NetworkMemberStatus::Connected)
        ->and($members[1])->network_status->toBe(NetworkMemberStatus::Degraded)->network_issue->toBe('no handshake with node-3 for 6 min')
        ->and($members[2])->network_status->toBe(NetworkMemberStatus::Unreachable)
        ->and($ssh->scriptAt(0))->toContain('wg show databasify dump | tail -n +2');
    Queue::assertNotPushed(SyncWireguardNetworkJob::class);
});

it('resyncs a network when a member is missing a peer or never connected', function () {
    Queue::fake([SyncWireguardNetworkJob::class]);
    $network = meshOf(2);
    $now = 1_800_000_000;
    fakeSsh()->respond(statusOutput($now, []))->respond(statusOutput($now, ['KEY-1' => 0]));

    resolve(CheckWireguardNetwork::class)->handle($network);

    $members = Server::query()->orderBy('id')->get();
    expect($members[0])->network_status->toBe(NetworkMemberStatus::OutOfSync)->network_issue->toBe('missing node-2')
        ->and($members[1])->network_status->toBe(NetworkMemberStatus::Degraded)->network_issue->toBe('never connected to node-1');
    Queue::assertPushed(SyncWireguardNetworkJob::class, 1);
});

it('opens the wireguard port only to the other members', function () {
    $network = meshOf(3);
    $ssh = fakeSsh();

    resolve(SyncWireguardNetwork::class)->handle($network);

    expect($ssh->scriptAt(0))
        ->toContain("elements = { %s } }\\n' '203.0.113.2, 203.0.113.3'")
        ->toContain("udp dport %s ip saddr @members accept\\n' '51820'")
        ->toContain("printf 'delete table inet databasify_network\\n'")->not->toContain('flush table')
        ->toContain("ufw_open databasify-network 'from 203.0.113.2 to any port 51820 proto udp' 'from 203.0.113.3 to any port 51820 proto udp' 'in on databasify'")
        ->not->toContain("ufw_open databasify-network '51820/udp'")
        ->toContain("firewalld_open databasify-network '51820/udp'");
});

it('removes the network firewall when a server leaves', function () {
    Queue::fake([SyncWireguardNetworkJob::class]);
    $network = meshOf(1);
    $ssh = fakeSsh();

    resolve(LeaveWireguardNetwork::class)->handle(Server::query()->sole(), $network);

    expect($ssh->scriptAt(0))->toContain('nft delete table inet databasify_network')->toContain('rm -f /etc/databasify/nftables/databasify_network.nft')
        ->and(Server::query()->sole())->network_status->toBeNull();
});

it('queues a check only for databasify networks that have members', function () {
    Queue::fake([CheckWireguardNetworkJob::class]);
    $mesh = meshOf(2);
    Network::factory()->wireguard(5)->create();
    Server::factory()->onNetwork(Network::factory()->create())->create();

    $this->artisan('networks:reconcile')->assertSuccessful();

    Queue::assertPushed(CheckWireguardNetworkJob::class, 1);
    Queue::assertPushed(CheckWireguardNetworkJob::class, fn (CheckWireguardNetworkJob $job): bool => $job->network->is($mesh));
});

it('shows member health on the server and providers pages', function () {
    $this->actingAs(User::factory()->create());
    $network = meshOf(2);
    $server = Server::query()->orderBy('id')->first();
    $server->recordNetworkHealth(NetworkMemberStatus::Degraded, 'no handshake with node-2 for 6 min');
    Server::query()->orderByDesc('id')->first()->recordNetworkHealth(NetworkMemberStatus::Connected);

    $this->get(projectUrl("/servers/{$server->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('server.data.network_status', 'degraded')
        ->where('server.data.network_issue', 'no handshake with node-2 for 6 min'));
    $this->get(projectUrl('/providers'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('networks.0.id', $network->id)
        ->where('networks.0.servers_count', 2)
        ->where('networks.0.connected_servers_count', 1));
});

it('names a new network after the first unused number, whatever its range', function () {
    Network::factory()->create(['name' => 'databasify-0', 'cidr' => '10.240.0.0/24']);
    Network::factory()->create(['name' => 'databasify-1', 'cidr' => '10.240.1.0/24']);

    expect(resolve(CreateWireguardNetwork::class)->handle(24, collect()))->name->toBe('databasify-2')->cidr->toBe('100.64.0.0/24');
});
