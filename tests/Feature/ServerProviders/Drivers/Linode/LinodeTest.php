<?php

declare(strict_types=1);

use App\DTOs\Providers\NewProviderServer;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\ServerProvider;
use App\ServerProviders\Drivers\Linode\Linode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const LINODE_API = 'https://api.linode.com/v4';

function linode(): Linode
{
    return new Linode(new ServerProvider(['credentials' => ['token' => 'secret-token']]));
}

it('lists regions with linodes, plans the region offers, and vpc subnets', function () {
    Http::fake([
        LINODE_API.'/regions/de-fra-2' => Http::response(['id' => 'de-fra-2', 'capabilities' => ['Linodes', 'VPCs']]),
        LINODE_API.'/regions*' => Http::response(['data' => [
            ['id' => 'us-east', 'label' => 'Newark, NJ', 'country' => 'us', 'status' => 'ok', 'capabilities' => ['Linodes', 'VPCs']],
            ['id' => 'de-fra-2', 'label' => 'Frankfurt 2, DE', 'country' => 'de', 'status' => 'ok', 'capabilities' => ['Linodes']],
            ['id' => 'xx-old', 'label' => 'Old', 'country' => 'us', 'status' => 'outage', 'capabilities' => ['Linodes']],
        ]]),
        LINODE_API.'/linode/types*' => Http::response(['data' => [
            ['id' => 'g6-standard-1', 'class' => 'standard', 'vcpus' => 1, 'memory' => 2048, 'disk' => 51200, 'price' => ['monthly' => 12.0], 'region_prices' => [['id' => 'de-fra-2', 'monthly' => 14.4]]],
            ['id' => 'g6-nanode-1', 'class' => 'nanode', 'vcpus' => 1, 'memory' => 1024, 'disk' => 25600, 'price' => ['monthly' => 5.0], 'region_prices' => []],
            ['id' => 'g6-dedicated-2', 'class' => 'dedicated', 'vcpus' => 2, 'memory' => 4096, 'disk' => 81920, 'price' => ['monthly' => 36.0], 'region_prices' => []],
            ['id' => 'g7-premium-2', 'class' => 'premium', 'vcpus' => 2, 'memory' => 4096, 'disk' => 81920, 'price' => ['monthly' => 43.0], 'region_prices' => []],
            ['id' => 'g1-gpu-rtx6000-1', 'class' => 'gpu', 'vcpus' => 8, 'memory' => 32768, 'disk' => 655360, 'price' => ['monthly' => 1000.0], 'region_prices' => []],
        ]]),
        LINODE_API.'/vpcs*' => Http::response(['data' => [
            ['id' => 3, 'label' => 'databases', 'region' => 'de-fra-2', 'subnets' => [['id' => 31, 'label' => 'main', 'ipv4' => '10.0.0.0/24'], ['id' => 32, 'label' => 'backup', 'ipv4' => '10.0.1.0/24']]],
            ['id' => 4, 'label' => 'elsewhere', 'region' => 'us-east', 'subnets' => [['id' => 41, 'label' => 'main', 'ipv4' => '10.9.0.0/24']]],
        ]]),
    ]);

    expect(array_map(fn ($region) => [$region->id, $region->country], linode()->regions()))->toBe([['de-fra-2', 'DE'], ['us-east', 'US']])
        ->and(array_map(fn ($plan) => $plan->toArray(), linode()->plans('de-fra-2')))->toBe([
            ['id' => 'g6-nanode-1', 'family' => 'Shared CPU', 'cpus' => 1, 'memory_mb' => 1024, 'disk_gb' => 25, 'monthly_price' => 5.0],
            ['id' => 'g6-standard-1', 'family' => 'Shared CPU', 'cpus' => 1, 'memory_mb' => 2048, 'disk_gb' => 50, 'monthly_price' => 14.4],
            ['id' => 'g6-dedicated-2', 'family' => 'Dedicated CPU', 'cpus' => 2, 'memory_mb' => 4096, 'disk_gb' => 80, 'monthly_price' => 36.0],
        ])
        ->and(array_map(fn ($network) => [$network->externalId, $network->name, $network->cidr], linode()->networks('de-fra-2')))->toBe([
            ['31', 'databases / main', '10.0.0.0/24'],
            ['32', 'databases / backup', '10.0.1.0/24'],
        ]);
});

it('creates a linode with the app key and a vpc interface, then reads its addresses', function () {
    Http::fake([
        LINODE_API.'/linode/instances' => Http::response(['id' => 777, 'ipv4' => ['203.0.113.40']]),
        LINODE_API.'/linode/instances/777/configs' => Http::response(['data' => [['interfaces' => [
            ['purpose' => 'public'],
            ['purpose' => 'vpc', 'subnet_id' => 31, 'ipv4' => ['vpc' => '10.0.0.5']],
        ]]]]),
        LINODE_API.'/linode/instances/777' => Http::sequence()
            ->push(['status' => 'provisioning', 'ipv4' => ['203.0.113.40']])
            ->push(['status' => 'running', 'ipv4' => ['203.0.113.40']]),
    ]);

    $id = linode()->createServer(new NewProviderServer('db-07', 'de-fra-2', 'g6-standard-1', 'linode/ubuntu26.04', '31', linode()->findKey('ssh-ed25519 AAAA') ?? '', 'ssh-ed25519 AAAA'));

    expect($id)->toBe('777')
        ->and(linode()->findServer($id)->running)->toBeFalse()
        ->and(linode()->findServer($id))->running->toBeTrue()->publicIp->toBe('203.0.113.40')->privateIp->toBe('10.0.0.5');
    Http::assertSent(fn (Request $request): bool => $request->url() === LINODE_API.'/linode/instances'
        && $request['authorized_keys'] === ['ssh-ed25519 AAAA']
        && $request['image'] === 'linode/ubuntu26.04'
        && $request['interfaces'] === [['purpose' => 'public'], ['purpose' => 'vpc', 'subnet_id' => 31]]
        && strlen($request['root_pass']) === 32);
});

it('destroys a linode and reports errors without the token', function () {
    Http::fake([
        LINODE_API.'/linode/instances/777' => Http::response([]),
        LINODE_API.'/profile' => Http::response(['errors' => [['reason' => 'Invalid Token']]], 401),
    ]);

    linode()->deleteServer('777');

    expect(fn () => linode()->verify())
        ->toThrow(fn (ProviderRequestFailed $exception) => expect($exception->getMessage())->toBe('Linode answered 401. Invalid Token')->not->toContain('secret-token'));
});

it('creates a vpc with one subnet and returns that subnet', function () {
    Http::fake([LINODE_API.'/vpcs' => Http::response(['id' => 8, 'label' => 'databasify-de-fra-2-ab12', 'region' => 'de-fra-2', 'subnets' => [['id' => 81, 'label' => 'databasify', 'ipv4' => '10.0.0.0/24']]])]);

    expect(linode()->createNetwork('de-fra-2', 'databasify-de-fra-2-ab12'))
        ->externalId->toBe('81')->name->toBe('databasify-de-fra-2-ab12 / databasify')->cidr->toBe('10.0.0.0/24');
    Http::assertSent(fn (Request $request): bool => $request['subnets'] === [['label' => 'databasify', 'ipv4' => '10.0.0.0/24']]);
});

it('deletes a subnet, or its vpc when it is the only one', function () {
    Http::fake([
        LINODE_API.'/vpcs?*' => Http::response(['data' => [
            ['id' => 3, 'subnets' => [['id' => 31], ['id' => 32]]],
            ['id' => 8, 'subnets' => [['id' => 81]]],
        ]]),
        LINODE_API.'/vpcs/*' => Http::response([]),
    ]);

    linode()->deleteNetwork('32');
    linode()->deleteNetwork('81');
    linode()->deleteNetwork('999');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === LINODE_API.'/vpcs/3/subnets/32');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === LINODE_API.'/vpcs/8');
    Http::assertSentCount(5);
});
