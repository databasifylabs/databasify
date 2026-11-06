<?php

declare(strict_types=1);

use App\DTOs\Providers\NewProviderServer;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\ServerProvider;
use App\ServerProviders\Drivers\DigitalOcean\DigitalOcean;
use App\Support\Ssh\Fingerprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const DO_API = 'https://api.digitalocean.com/v2';

it('lists regions, sizes of at least 1 GB the region offers, and vpcs', function () {
    Http::fake([
        DO_API.'/regions*' => Http::response(['regions' => [
            ['slug' => 'nyc3', 'name' => 'New York 3', 'available' => true],
            ['slug' => 'blr1', 'name' => 'Bangalore 1', 'available' => true],
            ['slug' => 'old1', 'name' => 'Old', 'available' => false],
        ]]),
        DO_API.'/sizes*' => Http::response(['sizes' => [
            ['slug' => 's-2vcpu-4gb', 'vcpus' => 2, 'memory' => 4096, 'disk' => 80, 'price_monthly' => 24, 'available' => true, 'regions' => ['blr1'], 'description' => 'Basic AMD'],
            ['slug' => 's-1vcpu-2gb', 'vcpus' => 1, 'memory' => 2048, 'disk' => 50, 'price_monthly' => 12, 'available' => true, 'regions' => ['blr1', 'nyc3'], 'description' => 'Basic'],
            ['slug' => 's-1vcpu-512mb-10gb', 'vcpus' => 1, 'memory' => 512, 'disk' => 10, 'price_monthly' => 4, 'available' => true, 'regions' => ['blr1'], 'description' => 'Basic'],
            ['slug' => 's-1vcpu-1gb-intel', 'vcpus' => 1, 'memory' => 1024, 'disk' => 25, 'price_monthly' => 8, 'available' => true, 'regions' => ['blr1'], 'description' => 'Basic Intel'],
            ['slug' => 'g-2vcpu-8gb', 'vcpus' => 2, 'memory' => 8192, 'disk' => 25, 'price_monthly' => 63, 'available' => true, 'regions' => ['blr1'], 'description' => 'General Purpose'],
            ['slug' => 'gd-2vcpu-8gb', 'vcpus' => 2, 'memory' => 8192, 'disk' => 25, 'price_monthly' => 63, 'available' => true, 'regions' => ['blr1'], 'description' => 'General Purpose 2x SSD'],
            ['slug' => 'sv5-1vcpu-1gb', 'vcpus' => 1, 'memory' => 1024, 'disk' => 30, 'price_monthly' => 18, 'available' => true, 'regions' => ['blr1'], 'description' => 'v5 Shared'],
            ['slug' => 'c2-2vcpu-4gb', 'vcpus' => 2, 'memory' => 4096, 'disk' => 50, 'price_monthly' => 54, 'available' => true, 'regions' => ['blr1'], 'description' => 'CPU Intensive 5x SSD'],
            ['slug' => 's-8vcpu-16gb', 'vcpus' => 8, 'memory' => 16384, 'disk' => 320, 'price_monthly' => 96, 'available' => true, 'regions' => ['nyc3'], 'description' => 'Basic'],
            ['slug' => 's-retired', 'vcpus' => 1, 'memory' => 1024, 'disk' => 25, 'price_monthly' => 5, 'available' => false, 'regions' => [], 'description' => 'Basic'],
        ]]),
        DO_API.'/vpcs*' => Http::response(['vpcs' => [
            ['id' => 'vpc-1', 'name' => 'default-blr1', 'region' => 'blr1', 'ip_range' => '10.104.0.0/20', 'default' => true],
            ['id' => 'vpc-2', 'name' => 'default-nyc3', 'region' => 'nyc3', 'ip_range' => '10.108.0.0/20', 'default' => true],
        ]]),
    ]);
    $driver = new DigitalOcean(new ServerProvider(['credentials' => ['token' => 'secret-token']]));

    expect(array_map(fn ($region) => [$region->id, $region->country], $driver->regions()))->toBe([['blr1', 'IN'], ['nyc3', 'US']])
        ->and(array_map(fn ($plan) => [$plan->id, $plan->family], $driver->plans('blr1')))->toBe([
            ['s-1vcpu-1gb-intel', 'Basic Intel'],
            ['s-1vcpu-2gb', 'Basic'],
            ['sv5-1vcpu-1gb', 'v5 Shared'],
            ['s-2vcpu-4gb', 'Basic AMD'],
            ['c2-2vcpu-4gb', 'CPU Intensive 5x SSD'],
            ['g-2vcpu-8gb', 'General Purpose'],
            ['gd-2vcpu-8gb', 'General Purpose 2x SSD'],
        ])
        ->and($driver->plans('blr1')[0]->toArray())->toBe(['id' => 's-1vcpu-1gb-intel', 'family' => 'Basic Intel', 'cpus' => 1, 'memory_mb' => 1024, 'disk_gb' => 25, 'monthly_price' => 8.0])
        ->and(array_map(fn ($network) => $network->toArray(), $driver->networks('blr1')))->toBe([
            ['external_id' => 'vpc-1', 'name' => 'default-blr1', 'cidr' => '10.104.0.0/20', 'region' => 'blr1', 'is_default' => true],
        ]);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('reuses the app key by fingerprint or uploads it once', function () {
    $publicKey = 'ssh-ed25519 '.base64_encode('key-bytes').' databasify';
    $fingerprint = Fingerprint::md5($publicKey);
    Http::fake([
        DO_API."/account/keys/{$fingerprint}" => Http::sequence()->push(['id' => 'not_found'], 404)->push(['ssh_key' => ['id' => 77]]),
        DO_API.'/account/keys' => Http::response(['ssh_key' => ['id' => 42]], 201),
    ]);
    $driver = new DigitalOcean(new ServerProvider(['credentials' => ['token' => 't']]));

    expect($driver->findKey($publicKey))->toBeNull()
        ->and($driver->createKey('databasify-app', $publicKey))->toBe('42')
        ->and($driver->findKey($publicKey))->toBe('77')
        ->and($fingerprint)->toBe(implode(':', str_split(md5('key-bytes'), 2)));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['public_key'] === $publicKey && $request['name'] === 'databasify-app');
});

it('creates a droplet in the chosen vpc and reads its addresses', function () {
    Http::fake([
        DO_API.'/droplets' => Http::response(['droplet' => ['id' => 9001]], 202),
        DO_API.'/droplets/9001' => Http::sequence()
            ->push(['droplet' => ['status' => 'new', 'networks' => ['v4' => []]]])
            ->push(['droplet' => ['status' => 'active', 'networks' => ['v4' => [
                ['ip_address' => '10.104.0.12', 'type' => 'private'],
                ['ip_address' => '203.0.113.20', 'type' => 'public'],
            ]]]]),
    ]);
    $driver = new DigitalOcean(new ServerProvider(['credentials' => ['token' => 't']]));

    $id = $driver->createServer(new NewProviderServer('db-04', 'blr1', 's-1vcpu-2gb', 'ubuntu-26-04-x64', 'vpc-1', '42', 'ssh-ed25519 AAAA'));
    $starting = $driver->findServer($id);
    $running = $driver->findServer($id);

    expect($id)->toBe('9001')
        ->and($starting->isReachable())->toBeFalse()
        ->and($running)->running->toBeTrue()->publicIp->toBe('203.0.113.20')->privateIp->toBe('10.104.0.12');
    Http::assertSent(fn (Request $request): bool => $request->url() === DO_API.'/droplets' && $request->data() === [
        'name' => 'db-04', 'region' => 'blr1', 'size' => 's-1vcpu-2gb', 'image' => 'ubuntu-26-04-x64',
        'ssh_keys' => ['42'], 'vpc_uuid' => 'vpc-1', 'ipv6' => false, 'monitoring' => true, 'tags' => ['databasify'],
    ]);
});

it('destroys a droplet and treats a missing one as gone', function () {
    Http::fake([DO_API.'/droplets/1' => Http::response(null, 204), DO_API.'/droplets/2' => Http::response(['message' => 'gone'], 404)]);
    $driver = new DigitalOcean(new ServerProvider(['credentials' => ['token' => 't']]));

    $driver->deleteServer('1');
    $driver->deleteServer('2');

    Http::assertSentCount(2);
});

it('reports the provider message without the token', function () {
    Http::fake([DO_API.'/account' => Http::response(['id' => 'unauthorized', 'message' => 'Unable to authenticate you'], 401)]);

    expect(fn () => (new DigitalOcean(new ServerProvider(['credentials' => ['token' => 'secret-token']])))->verify())
        ->toThrow(fn (ProviderRequestFailed $exception) => expect($exception->getMessage())->toBe('DigitalOcean answered 401. Unable to authenticate you')->not->toContain('secret-token'));
});

it('creates a vpc in the region', function () {
    Http::fake([DO_API.'/vpcs' => Http::response(['vpc' => ['id' => 'vpc-9', 'name' => 'databasify-blr1-ab12', 'region' => 'blr1', 'ip_range' => '10.122.16.0/20', 'default' => false]], 201)]);

    expect((new DigitalOcean(new ServerProvider(['credentials' => ['token' => 't']])))->createNetwork('blr1', 'databasify-blr1-ab12')->toArray())
        ->toBe(['external_id' => 'vpc-9', 'name' => 'databasify-blr1-ab12', 'cidr' => '10.122.16.0/20', 'region' => 'blr1', 'is_default' => false]);
    Http::assertSent(fn (Request $request): bool => $request->data() === ['name' => 'databasify-blr1-ab12', 'region' => 'blr1']);
});
