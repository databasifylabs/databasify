<?php

declare(strict_types=1);

use App\DTOs\Providers\NewProviderServer;
use App\Enums\Servers\OperatingSystem;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\ServerProvider;
use App\ServerProviders\Drivers\Vultr\Vultr;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const VULTR_API = 'https://api.vultr.com/v2';

function vultr(): Vultr
{
    return new Vultr(new ServerProvider(['credentials' => ['token' => 'secret-key']]));
}

it('lists regions, plans of at least 1 GB in stock there with their family, and vpcs', function () {
    Http::fake([
        VULTR_API.'/regions/fra/availability' => Http::response(['available_plans' => ['vc2-1c-1gb', 'vhp-1c-2gb-amd', 'vc2-1c-0.5gb', 'vcg-a100-1c-6g-4vram']]),
        VULTR_API.'/regions*' => Http::response(['regions' => [
            ['id' => 'fra', 'city' => 'Frankfurt', 'country' => 'DE'],
            ['id' => 'ewr', 'city' => 'New Jersey', 'country' => 'US'],
        ]]),
        VULTR_API.'/plans*' => Http::response(['plans' => [
            ['id' => 'vhp-1c-2gb-amd', 'type' => 'vhp', 'vcpu_count' => 1, 'ram' => 2048, 'disk' => 50, 'monthly_cost' => 12, 'locations' => ['fra']],
            ['id' => 'vc2-1c-1gb', 'type' => 'vc2', 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 25, 'monthly_cost' => 5, 'locations' => ['fra', 'ewr']],
            ['id' => 'vc2-1c-0.5gb', 'type' => 'vc2', 'vcpu_count' => 1, 'ram' => 512, 'disk' => 10, 'monthly_cost' => 2.5, 'locations' => ['fra']],
            ['id' => 'vcg-a100-1c-6g-4vram', 'type' => 'vcg', 'vcpu_count' => 1, 'ram' => 6144, 'disk' => 70, 'monthly_cost' => 90, 'locations' => ['fra']],
            ['id' => 'vhf-1c-1gb', 'type' => 'vhf', 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 32, 'monthly_cost' => 6, 'locations' => ['ewr']],
            ['id' => 'voc-c-8c-16gb-150s-amd', 'type' => 'voc', 'vcpu_count' => 8, 'ram' => 16384, 'disk' => 150, 'monthly_cost' => 240, 'locations' => ['fra']],
        ]]),
        VULTR_API.'/vpcs*' => Http::response(['vpcs' => [
            ['id' => 'vpc-a', 'region' => 'fra', 'description' => 'databases', 'v4_subnet' => '10.6.96.0', 'v4_subnet_mask' => 20],
            ['id' => 'vpc-b', 'region' => 'fra', 'description' => '', 'v4_subnet' => '10.7.0.0', 'v4_subnet_mask' => 24],
            ['id' => 'vpc-c', 'region' => 'ewr', 'description' => 'other', 'v4_subnet' => '10.8.0.0', 'v4_subnet_mask' => 24],
        ]]),
    ]);

    expect(array_map(fn ($region) => [$region->id, $region->name, $region->country], vultr()->regions()))->toBe([['fra', 'Frankfurt', 'DE'], ['ewr', 'New Jersey', 'US']])
        ->and(array_map(fn ($plan) => [$plan->id, $plan->family, $plan->monthlyPrice], vultr()->plans('fra')))->toBe([
            ['vc2-1c-1gb', 'Regular Cloud Compute', 5.0],
            ['vhp-1c-2gb-amd', 'High Performance AMD', 12.0],
        ])
        ->and(array_map(fn ($network) => [$network->externalId, $network->name, $network->cidr], vultr()->networks('fra')))->toBe([
            ['vpc-a', 'databases', '10.6.96.0/20'],
            ['vpc-b', 'vpc-b', '10.7.0.0/24'],
        ]);
});

it('reuses an uploaded key with the same body or uploads it once', function () {
    $publicKey = 'ssh-ed25519 AAAAkey databasify';
    Http::fake([
        VULTR_API.'/ssh-keys?*' => Http::sequence()
            ->push(['ssh_keys' => [['id' => 'other', 'ssh_key' => 'ssh-ed25519 BBBB someone']]])
            ->push(['ssh_keys' => [['id' => 'mine', 'ssh_key' => 'ssh-ed25519 AAAAkey renamed']]]),
        VULTR_API.'/ssh-keys' => Http::response(['ssh_key' => ['id' => 'new']], 201),
    ]);

    expect(vultr()->findKey($publicKey))->toBeNull()
        ->and(vultr()->createKey('databasify-app', $publicKey))->toBe('new')
        ->and(vultr()->findKey($publicKey))->toBe('mine');
});

it('creates an instance from the ubuntu image in the chosen vpc and reads its addresses', function () {
    Http::fake([
        VULTR_API.'/instances' => Http::response(['instance' => ['id' => 'inst-1']], 202),
        VULTR_API.'/instances/inst-1/vpcs' => Http::response(['vpcs' => [['id' => 'vpc-a', 'ip_address' => '10.6.96.3']]]),
        VULTR_API.'/instances/inst-1' => Http::sequence()
            ->push(['instance' => ['status' => 'pending', 'power_status' => 'stopped', 'main_ip' => '0.0.0.0']])
            ->push(['instance' => ['status' => 'active', 'power_status' => 'running', 'main_ip' => '203.0.113.30']]),
    ]);

    $id = vultr()->createServer(new NewProviderServer('db-06', 'fra', 'vc2-1c-1gb', 2760, 'vpc-a', 'key-1', 'ssh-ed25519 AAAA'));
    $starting = vultr()->findServer($id);

    expect($id)->toBe('inst-1')
        ->and($starting)->running->toBeFalse()->publicIp->toBeNull()
        ->and(vultr()->findServer($id))->running->toBeTrue()->publicIp->toBe('203.0.113.30')->privateIp->toBe('10.6.96.3');
    Http::assertSent(fn (Request $request): bool => $request->url() === VULTR_API.'/instances' && $request->data() === [
        'region' => 'fra', 'plan' => 'vc2-1c-1gb', 'os_id' => 2760, 'label' => 'db-06', 'hostname' => 'db-06',
        'sshkey_id' => ['key-1'], 'attach_vpc' => ['vpc-a'], 'enable_ipv6' => false, 'backups' => 'disabled', 'tags' => ['databasify'],
    ]);
});

it('creates an instance from the catalog image of each operating system', function (OperatingSystem $system, int $osId) {
    Http::fake([VULTR_API.'/instances' => Http::response(['instance' => ['id' => 'inst-2']], 202)]);

    vultr()->createServer(new NewProviderServer('db-07', 'fra', 'vc2-1c-1gb', ServerProvider::providerFor('vultr')->image($system) ?? 0, null, 'key-1', 'ssh-ed25519 AAAA'));

    Http::assertSent(fn (Request $request): bool => $request->url() === VULTR_API.'/instances' && $request['os_id'] === $osId);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), VULTR_API.'/os'));
})->with([
    'ubuntu 24.04' => [OperatingSystem::Ubuntu2404, 2284],
    'debian 12' => [OperatingSystem::Debian12, 2136],
    'debian 13' => [OperatingSystem::Debian13, 2625],
    'rocky 9' => [OperatingSystem::Rocky9, 1869],
    'almalinux 9' => [OperatingSystem::Alma9, 1868],
]);

it('reports a dropped connection without retrying, so a create is never repeated', function () {
    Http::fake([VULTR_API.'/instances' => Http::failedConnection()]);

    expect(fn () => vultr()->createServer(new NewProviderServer('db-08', 'fra', 'vc2-1c-1gb', 2136, null, 'key-1', 'ssh-ed25519 AAAA')))->toThrow(ProviderRequestFailed::class, 'Vultr could not be reached.');
    Http::assertSentCount(1);
});

it('destroys an instance and reports errors without the key', function () {
    Http::fake([
        VULTR_API.'/instances/inst-1' => Http::response(null, 204),
        VULTR_API.'/account' => Http::response(['error' => 'Invalid API token.', 'status' => 401], 401),
    ]);

    vultr()->deleteServer('inst-1');

    expect(fn () => vultr()->verify())
        ->toThrow(fn (ProviderRequestFailed $exception) => expect($exception->getMessage())->toBe('Vultr answered 401. Invalid API token.')->not->toContain('secret-key'));
});

it('creates a vpc in the region', function () {
    Http::fake([VULTR_API.'/vpcs' => Http::response(['vpc' => ['id' => 'vpc-new', 'region' => 'fra', 'description' => 'databasify-fra-ab12', 'v4_subnet' => '10.9.96.0', 'v4_subnet_mask' => 20]], 201)]);

    expect(vultr()->createNetwork('fra', 'databasify-fra-ab12'))
        ->externalId->toBe('vpc-new')->name->toBe('databasify-fra-ab12')->cidr->toBe('10.9.96.0/20');
});
