<?php

declare(strict_types=1);

use App\Actions\Networks\CreateWireguardNetwork;
use App\Actions\Networks\LeaveWireguardNetwork;
use App\Actions\Networks\SyncWireguardNetwork;
use App\Actions\Servers\AddServer;
use App\Actions\Servers\DeleteServer;
use App\Actions\Servers\ProvisionServer;
use App\DTOs\Servers\NewServer;
use App\DTOs\Servers\PrivateNetworkChoice;
use App\Enums\Servers\NetworkKind;
use App\Enums\Servers\ServerLogStatus;
use App\Enums\Servers\ServerStatus;
use App\Jobs\Networks\JoinWireguardNetworkJob;
use App\Jobs\Networks\LeaveWireguardNetworkJob;
use App\Jobs\Networks\SyncWireguardNetworkJob;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\User;
use App\Services\Networks\WireguardAddressAllocator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

const WIREGUARD_HOST_FACTS = '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":1,"memory_mb":1024,"disk_gb":25}'."\n";

beforeEach(fn () => inProject());

function grabAddressesAfterLookupOn(Network $network, int $times): void
{
    DB::listen(function (QueryExecuted $query) use ($network, &$times): void {
        if ($times === 0 || ! str_starts_with($query->sql, 'select "private_ip" from "servers"')) {
            return;
        }

        $times--;
        $taken = $network->servers()->count() + 1;
        Server::factory()->onNetwork($network)->createQuietly(['private_ip' => "100.64.0.{$taken}"]);
    });
}

function addServerOn(Network $network): Server
{
    return resolve(AddServer::class)->handle(new NewServer('db-1', '203.0.113.10', 22, 'root', null, false, new PrivateNetworkChoice(networkId: $network->id)));
}

it('gives each databasify network the first free range', function () {
    Network::factory()->wireguard(0)->create();
    Network::factory()->wireguard(2)->create();

    $network = resolve(CreateWireguardNetwork::class)->handle(24, collect());

    expect($network)
        ->name->toBe('databasify-1')
        ->cidr->toBe('100.64.1.0/24')
        ->kind->toBe(NetworkKind::Wireguard)
        ->listen_port->toBe(51820);
});

it('hands out the lowest free address and reuses one a member left', function () {
    $network = Network::factory()->wireguard()->create();
    $first = Server::factory()->onNetwork($network)->create(['private_ip' => '100.64.0.1']);
    Server::factory()->onNetwork($network)->create(['private_ip' => '100.64.0.2']);
    $allocate = resolve(WireguardAddressAllocator::class);

    expect($allocate->next($network))->toBe('100.64.0.3')
        ->and($allocate->next($network, $first))->toBe('100.64.0.1');

    $first->delete();

    expect($allocate->next($network))->toBe('100.64.0.1');
});

it('takes the next address when another server grabs it between lookup and save', function () {
    Queue::fake([ProvisionServerJob::class]);
    $network = Network::factory()->wireguard()->create();
    grabAddressesAfterLookupOn($network, 1);

    expect(addServerOn($network)->fresh())->private_ip->toBe('100.64.0.2')->network_id->toBe($network->id);
});

it('asks to try again when the address is taken twice in a row', function () {
    Queue::fake([ProvisionServerJob::class]);
    $network = Network::factory()->wireguard()->create();
    grabAddressesAfterLookupOn($network, 2);

    expect(fn () => addServerOn($network))->toThrow(ValidationException::class, 'That address was just taken, try again.')
        ->and(Server::query()->where('name', 'db-1')->exists())->toBeFalse();
});

it('rethrows a unique violation that is not an address clash', function () {
    Queue::fake([ProvisionServerJob::class]);
    $network = Network::factory()->wireguard()->create();
    Server::factory()->create(['name' => 'db-1']);

    expect(fn () => addServerOn($network))->toThrow(UniqueConstraintViolationException::class);
});

it('adds a server on a new databasify network without asking for an address', function () {
    Queue::fake([ProvisionServerJob::class]);
    $this->actingAs(User::factory()->create());

    $this->post(projectUrl('/servers'), ['name' => 'db-1', 'public_ip' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root', 'network' => 'wireguard', 'private_ip' => '10.9.9.9'])
        ->assertSessionHasNoErrors();
    $this->post(projectUrl('/servers'), ['name' => 'db-2', 'public_ip' => '203.0.113.11', 'ssh_port' => 22, 'ssh_user' => 'root', 'network' => (string) Network::query()->sole()->id])
        ->assertSessionHasNoErrors();

    expect(Server::query()->where('name', 'db-1')->sole())->private_ip->toBe('100.64.0.1')->network->kind->toBe(NetworkKind::Wireguard)
        ->and(Server::query()->where('name', 'db-2')->sole())->private_ip->toBe('100.64.0.2');
});

it('joins the databasify network as the last setup step', function () {
    Queue::fake([SyncWireguardNetworkJob::class]);
    $network = Network::factory()->wireguard()->create();
    $server = Server::factory()->onNetwork($network)->create(['private_ip' => '100.64.0.1']);
    $ssh = fakeSsh()->respond(WIREGUARD_HOST_FACTS)->respond()->respond()->respond()->respond()->respond()->respond()->respond("joined\nwireguard_public_key=PUBLIC-KEY-A\n");

    resolve(ProvisionServer::class)->handle($server, false);

    expect($server->fresh())->status->toBe(ServerStatus::Ready)->wireguard_public_key->toBe('PUBLIC-KEY-A')
        ->and($ssh->scriptAt(1))->not->toContain("grep -qxF '100.64.0.1'")
        ->and($ssh->scriptAt(7))
        ->toContain('wg genkey > /etc/wireguard/databasify.key')
        ->toContain("Address = %s\\n' '100.64.0.1/24'")
        ->toContain("ListenPort = %s\\n' '51820'")
        ->not->toContain('docker.service')
        ->toContain('systemctl restart wg-quick@databasify')
        ->toContain("ufw_open databasify-network 'in on databasify'")
        ->and($server->serverLogs()->pluck('name')->last())->toBe('Join Databasify network');
    Queue::assertPushed(SyncWireguardNetworkJob::class, fn (SyncWireguardNetworkJob $job): bool => $job->network->is($network));
});

it('writes every other member as a peer and applies it without a restart', function () {
    $network = Network::factory()->wireguard()->create();
    $a = Server::factory()->ready()->onNetwork($network)->create(['public_ip' => '203.0.113.1', 'private_ip' => '100.64.0.1', 'wireguard_public_key' => 'KEY-A']);
    Server::factory()->ready()->onNetwork($network)->create(['public_ip' => '203.0.113.2', 'private_ip' => '100.64.0.2', 'wireguard_public_key' => 'KEY-B']);
    Server::factory()->ready()->onNetwork($network)->create(['public_ip' => '203.0.113.3', 'private_ip' => '100.64.0.3', 'wireguard_public_key' => null]);
    $ssh = fakeSsh();

    resolve(SyncWireguardNetwork::class)->handle($network);

    expect($ssh->runs)->toHaveCount(2)
        ->and($ssh->scriptAt(0))
        ->toContain("PublicKey = %s\\n' 'KEY-B'")
        ->toContain("AllowedIPs = %s\\n' '100.64.0.2/32'")
        ->toContain("Endpoint = %s\\n' '203.0.113.2:51820'")
        ->not->toContain('KEY-A')
        ->toContain('wg syncconf databasify <(wg-quick strip databasify)')
        ->and($ssh->scriptAt(1))->toContain("'KEY-A'")->not->toContain("'KEY-B'")
        ->and($a->serverLogs()->sole()->name)->toBe('Update Databasify network');
});

it('keeps updating the other members when one cannot be reached', function () {
    $network = Network::factory()->wireguard()->create();
    $down = Server::factory()->ready()->onNetwork($network)->create(['private_ip' => '100.64.0.1', 'wireguard_public_key' => 'KEY-A']);
    $up = Server::factory()->ready()->onNetwork($network)->create(['private_ip' => '100.64.0.2', 'wireguard_public_key' => 'KEY-B']);
    fakeSsh()->respond("connection refused\n", 255)->respond("1 peer on the Databasify network\n");

    resolve(SyncWireguardNetwork::class)->handle($network);

    expect($down->serverLogs()->sole()->status)->toBe(ServerLogStatus::Failed)
        ->and($up->serverLogs()->sole()->status)->toBe(ServerLogStatus::Succeeded);
});

it('leaves the old databasify network and joins the new one when moved', function () {
    Queue::fake([LeaveWireguardNetworkJob::class, JoinWireguardNetworkJob::class]);
    $this->actingAs(User::factory()->create());
    $old = Network::factory()->wireguard()->create();
    $server = Server::factory()->ready()->onNetwork($old)->create(['private_ip' => '100.64.0.1', 'wireguard_public_key' => 'KEY-A']);

    $this->put(projectUrl("/servers/{$server->id}/network"), ['network' => 'wireguard'])->assertSessionHasNoErrors();

    expect($server->fresh())->network->cidr->toBe('100.64.1.0/24')->private_ip->toBe('100.64.1.1');
    Queue::assertPushed(LeaveWireguardNetworkJob::class, fn (LeaveWireguardNetworkJob $job): bool => $job->network->is($old));
    Queue::assertPushed(JoinWireguardNetworkJob::class, fn (JoinWireguardNetworkJob $job): bool => $job->server->is($server) && $job->network->cidr === '100.64.1.0/24');
});

it('takes the tunnel down and tells the rest when a server leaves', function () {
    Queue::fake([SyncWireguardNetworkJob::class]);
    $network = Network::factory()->wireguard()->create();
    $server = Server::factory()->ready()->onNetwork($network)->create(['private_ip' => '100.64.0.1', 'wireguard_public_key' => 'KEY-A']);
    $ssh = fakeSsh();

    resolve(LeaveWireguardNetwork::class)->handle($server, $network);

    expect($ssh->scriptAt(0))->toContain('systemctl disable --now wg-quick@databasify')->toContain('ufw_close databasify-network')->toContain('rm -f /etc/wireguard/databasify.conf')
        ->and($server->fresh()->wireguard_public_key)->toBeNull();
    Queue::assertPushed(SyncWireguardNetworkJob::class);
});

it('drops a deleted server from the rest of its databasify network', function () {
    Queue::fake([SyncWireguardNetworkJob::class]);
    $network = Network::factory()->wireguard()->create();
    $server = Server::factory()->ready()->onNetwork($network)->create(['private_ip' => '100.64.0.1']);
    $other = Server::factory()->ready()->onNetwork(Network::factory()->create())->create();

    resolve(DeleteServer::class)->handle($server);
    resolve(DeleteServer::class)->handle($other);

    Queue::assertPushedTimes(SyncWireguardNetworkJob::class, 1);
    Queue::assertPushed(SyncWireguardNetworkJob::class, fn (SyncWireguardNetworkJob $job): bool => $job->network->is($network));
});

it('never syncs the same databasify network twice at once', function () {
    $network = resolve(CreateWireguardNetwork::class)->handle(24, collect());
    $job = new SyncWireguardNetworkJob($network);

    $overlap = collect($job->middleware())->sole();

    expect($overlap)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($overlap->key)->toBe((string) $network->id)
        ->and($overlap->releaseAfter)->toBeGreaterThan(0)
        ->and($overlap->expiresAfter)->toBeGreaterThanOrEqual(900);
});
