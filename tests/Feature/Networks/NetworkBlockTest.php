<?php

declare(strict_types=1);

use App\Actions\Networks\CreateWireguardNetwork;
use App\Jobs\Networks\JoinWireguardNetworkJob;
use App\Jobs\Servers\LaunchProviderServerJob;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Network;
use App\Models\Server;
use App\Models\ServerProvider;
use App\Models\User;
use App\Services\Networks\WireguardAddressAllocator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Queue::fake([ProvisionServerJob::class, JoinWireguardNetworkJob::class, LaunchProviderServerJob::class]);
    inProject();
    $this->actingAs(User::factory()->create());
});

$server = ['name' => 'db-1', 'public_ip' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root', 'ssh_password' => 'secret'];

it('carves a databasify network of the chosen block size', function (?int $block, string $cidr) use ($server) {
    Network::factory()->wireguard()->create();

    $this->post(projectUrl('/servers'), [...$server, 'network' => 'wireguard', 'network_block' => $block])->assertSessionHasNoErrors();

    expect(Server::query()->sole())
        ->network->cidr->toBe($cidr)
        ->private_ip->toBe(Str::before($cidr, '.0/').'.1');
})->with([
    'default /24' => [null, '100.64.1.0/24'],
    '/23' => [23, '100.64.2.0/23'],
    '/22' => [22, '100.64.4.0/22'],
    '/20' => [20, '100.64.16.0/20'],
    '/16' => [16, '100.65.0.0/16'],
]);

it('refuses a block size outside the offered ones', function (int $block) use ($server) {
    $this->post(projectUrl('/servers'), [...$server, 'network' => 'wireguard', 'network_block' => $block])->assertSessionHasErrors('network_block');

    expect(Network::query()->count())->toBe(0);
})->with([8, 21, 25, 30]);

it('orders a provider server on a databasify network of the chosen block size', function () {
    $provider = ServerProvider::factory()->create();

    $this->post(projectUrl('/providers/servers'), ['server_provider_id' => $provider->id, 'name' => 'db-05', 'region' => 'blr1', 'plan' => 's-1vcpu-2gb', 'os' => 'ubuntu-26.04', 'network' => 'databasify:new', 'network_block' => 20])
        ->assertSessionHasNoErrors();

    expect(Server::query()->sole())->private_ip->toBe('100.64.0.1')->network->cidr->toBe('100.64.0.0/20');
});

it('skips blocks that overlap any existing network', function () {
    Network::factory()->create(['cidr' => '100.64.0.0/20']);
    Network::factory()->create(['cidr' => '100.64.17.0/24']);

    expect(resolve(CreateWireguardNetwork::class)->handle(22, collect()))->cidr->toBe('100.64.20.0/22');
});

it('skips blocks that overlap a subnet on a member server interface', function () {
    $member = Server::factory()->ready()->onNetwork(null)->create(['private_ip' => null, 'interface_subnets' => ['10.0.0.0/24', '100.64.0.0/16']]);

    $this->put(projectUrl("/servers/{$member->id}/network"), ['network' => 'wireguard', 'network_block' => 24])->assertSessionHasNoErrors();

    expect($member->fresh())->private_ip->toBe('100.65.0.1')->network->cidr->toBe('100.65.0.0/24');
});

it('fails when every block overlaps a member interface', function () {
    $member = Server::factory()->ready()->create(['interface_subnets' => ['100.64.0.0/10']]);

    expect(fn () => resolve(CreateWireguardNetwork::class)->handle(24, collect([$member])))
        ->toThrow(ValidationException::class, 'No free /24 block is left in 100.64.0.0/10. Choose a smaller block size.');
    expect(Network::query()->wireguard()->count())->toBe(0);
});

it('hands out addresses across the whole of a /22', function () {
    $network = Network::factory()->wireguard()->create(['cidr' => '100.64.4.0/22']);
    Server::factory()->onNetwork($network)->count(254)->sequence(fn ($sequence): array => ['private_ip' => '100.64.4.'.($sequence->index + 1)])->createQuietly();
    $allocate = resolve(WireguardAddressAllocator::class);

    expect($allocate->next($network))->toBe('100.64.4.255');

    Server::factory()->onNetwork($network)->createQuietly(['private_ip' => '100.64.4.255']);

    expect($allocate->next($network))->toBe('100.64.5.0');
});

it('never hands out the network or broadcast address and fails when the block is full', function () {
    $network = Network::factory()->wireguard()->create(['cidr' => '100.64.0.0/30']);
    $allocate = resolve(WireguardAddressAllocator::class);
    Server::factory()->onNetwork($network)->createQuietly(['private_ip' => $allocate->next($network)]);
    Server::factory()->onNetwork($network)->createQuietly(['private_ip' => $allocate->next($network)]);

    expect($network->servers()->orderBy('private_ip')->pluck('private_ip')->all())->toBe(['100.64.0.1', '100.64.0.2'])
        ->and(fn () => $allocate->next($network))->toThrow(RuntimeException::class, "{$network->name} has no free address left.");
});
