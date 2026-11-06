<?php

declare(strict_types=1);

use App\DTOs\Providers\NewProviderServer;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\ServerProvider;
use App\ServerProviders\Drivers\Hetzner\Hetzner;
use App\Support\Ssh\Fingerprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const HETZNER_API = 'https://api.hetzner.cloud/v1';

function hetzner(): Hetzner
{
    return new Hetzner(new ServerProvider(['credentials' => ['token' => 'secret-token']]));
}

it('lists locations, x86 server types of at least 1 GB in stock there, and networks in its zone', function () {
    Http::fake([
        HETZNER_API.'/locations?name=fsn1' => Http::response(['locations' => [['name' => 'fsn1', 'network_zone' => 'eu-central']]]),
        HETZNER_API.'/locations*' => Http::response(['locations' => [
            ['name' => 'hel1', 'city' => 'Helsinki', 'country' => 'FI', 'network_zone' => 'eu-central'],
            ['name' => 'ash', 'city' => 'Ashburn, VA', 'country' => 'US', 'network_zone' => 'us-east'],
        ]]),
        HETZNER_API.'/server_types*' => Http::response(['server_types' => [
            ['name' => 'cpx21', 'cores' => 3, 'memory' => 4.0, 'disk' => 80, 'cpu_type' => 'shared', 'architecture' => 'x86', 'locations' => [['name' => 'fsn1', 'available' => true, 'deprecation' => null]], 'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '9.5200000000']]]],
            ['name' => 'cx22', 'cores' => 2, 'memory' => 4.0, 'disk' => 40, 'cpu_type' => 'shared', 'architecture' => 'x86', 'locations' => [['name' => 'fsn1', 'available' => true, 'deprecation' => null]], 'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '4.5100000000']]]],
            ['name' => 'ccx13', 'cores' => 2, 'memory' => 8.0, 'disk' => 80, 'cpu_type' => 'dedicated', 'architecture' => 'x86', 'locations' => [['name' => 'fsn1', 'available' => true, 'deprecation' => null]], 'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '15.0000000000']]]],
            ['name' => 'cax11', 'cores' => 2, 'memory' => 4.0, 'disk' => 40, 'cpu_type' => 'shared', 'architecture' => 'arm', 'locations' => [['name' => 'fsn1', 'available' => true, 'deprecation' => null]], 'prices' => []],
            ['name' => 'cx11', 'cores' => 1, 'memory' => 2.0, 'disk' => 20, 'cpu_type' => 'shared', 'architecture' => 'x86', 'locations' => [['name' => 'fsn1', 'available' => true, 'deprecation' => ['unavailable_after' => '2024-01-01T00:00:00+00:00']]], 'prices' => []],
            ['name' => 'cpx31', 'cores' => 4, 'memory' => 8.0, 'disk' => 160, 'cpu_type' => 'shared', 'architecture' => 'x86', 'locations' => [['name' => 'fsn1', 'available' => false, 'deprecation' => null]], 'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '17.0000000000']]]],
            ['name' => 'cpx11', 'cores' => 2, 'memory' => 2.0, 'disk' => 40, 'cpu_type' => 'shared', 'architecture' => 'x86', 'locations' => [['name' => 'ash', 'available' => true, 'deprecation' => null]], 'prices' => []],
        ]]),
        HETZNER_API.'/networks*' => Http::response(['networks' => [
            ['id' => 11, 'name' => 'office', 'ip_range' => '10.0.0.0/16', 'subnets' => [['network_zone' => 'eu-central', 'ip_range' => '10.0.1.0/24']]],
            ['id' => 12, 'name' => 'virginia', 'ip_range' => '10.1.0.0/16', 'subnets' => [['network_zone' => 'us-east', 'ip_range' => '10.1.1.0/24']]],
        ]]),
    ]);

    expect(array_map(fn ($region) => [$region->id, $region->name, $region->country], hetzner()->regions()))->toBe([['ash', 'Ashburn, VA', 'US'], ['hel1', 'Helsinki', 'FI']])
        ->and(array_map(fn ($plan) => $plan->toArray(), hetzner()->plans('fsn1')))->toBe([
            ['id' => 'cx22', 'family' => 'Shared vCPU', 'cpus' => 2, 'memory_mb' => 4096, 'disk_gb' => 40, 'monthly_price' => 4.51],
            ['id' => 'cpx21', 'family' => 'Shared vCPU AMD', 'cpus' => 3, 'memory_mb' => 4096, 'disk_gb' => 80, 'monthly_price' => 9.52],
            ['id' => 'ccx13', 'family' => 'Dedicated vCPU AMD', 'cpus' => 2, 'memory_mb' => 8192, 'disk_gb' => 80, 'monthly_price' => 15.0],
        ])
        ->and(array_map(fn ($network) => $network->toArray(), hetzner()->networks('fsn1')))->toBe([
            ['external_id' => '11', 'name' => 'office', 'cidr' => '10.0.0.0/16', 'region' => 'fsn1', 'is_default' => false],
        ]);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('reuses the app key by fingerprint or uploads it once', function () {
    $publicKey = 'ssh-ed25519 '.base64_encode('key-bytes').' databasify';
    Http::fake([
        HETZNER_API.'/ssh_keys?*' => Http::sequence()->push(['ssh_keys' => []])->push(['ssh_keys' => [['id' => 7]]]),
        HETZNER_API.'/ssh_keys' => Http::response(['ssh_key' => ['id' => 5]], 201),
    ]);

    expect(hetzner()->findKey($publicKey))->toBeNull()
        ->and(hetzner()->createKey('databasify-app', $publicKey))->toBe('5')
        ->and(hetzner()->findKey($publicKey))->toBe('7');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['public_key'] === $publicKey && $request['name'] === 'databasify-app');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request['fingerprint'] === Fingerprint::md5($publicKey));
});

it('creates a server in the chosen network and reads its addresses', function () {
    Http::fake([
        HETZNER_API.'/servers' => Http::response(['server' => ['id' => 42]], 201),
        HETZNER_API.'/servers/42' => Http::sequence()
            ->push(['server' => ['status' => 'initializing', 'public_net' => ['ipv4' => ['ip' => '203.0.113.9']], 'private_net' => []]])
            ->push(['server' => ['status' => 'running', 'public_net' => ['ipv4' => ['ip' => '203.0.113.9']], 'private_net' => [['network' => 11, 'ip' => '10.0.1.2']]]]),
    ]);

    $id = hetzner()->createServer(new NewProviderServer('db-05', 'fsn1', 'cx22', 'ubuntu-26.04', '11', '5', 'ssh-ed25519 AAAA'));

    expect($id)->toBe('42')
        ->and(hetzner()->findServer($id)->running)->toBeFalse()
        ->and(hetzner()->findServer($id))->running->toBeTrue()->publicIp->toBe('203.0.113.9')->privateIp->toBe('10.0.1.2');
    Http::assertSent(fn (Request $request): bool => $request->url() === HETZNER_API.'/servers' && $request->data() === [
        'name' => 'db-05', 'location' => 'fsn1', 'server_type' => 'cx22', 'image' => 'ubuntu-26.04',
        'ssh_keys' => [5], 'networks' => [11], 'labels' => ['managed-by' => 'databasify'], 'start_after_create' => true,
    ]);
});

it('destroys a server and reports errors without the token', function () {
    Http::fake([
        HETZNER_API.'/servers/42' => Http::response(['action' => []]),
        HETZNER_API.'/servers*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], 401),
    ]);

    hetzner()->deleteServer('42');

    expect(fn () => hetzner()->verify())
        ->toThrow(fn (ProviderRequestFailed $exception) => expect($exception->getMessage())->toBe('Hetzner answered 401. unable to authenticate')->not->toContain('secret-token'));
});

it('creates a network with a subnet in the location zone', function () {
    Http::fake([
        HETZNER_API.'/locations*' => Http::response(['locations' => [['name' => 'fsn1', 'network_zone' => 'eu-central']]]),
        HETZNER_API.'/networks' => Http::response(['network' => ['id' => 99, 'name' => 'databasify-fsn1-ab12', 'ip_range' => '10.0.0.0/16']], 201),
    ]);

    expect(hetzner()->createNetwork('fsn1', 'databasify-fsn1-ab12')->toArray())
        ->toBe(['external_id' => '99', 'name' => 'databasify-fsn1-ab12', 'cidr' => '10.0.0.0/16', 'region' => 'fsn1', 'is_default' => false]);
    Http::assertSent(fn (Request $request): bool => $request->url() === HETZNER_API.'/networks' && $request['subnets'] === [['type' => 'cloud', 'network_zone' => 'eu-central', 'ip_range' => '10.0.0.0/24']]);
});
