<?php

declare(strict_types=1);

use App\DnsProviders\Contracts\DnsDriver;
use App\DnsProviders\Drivers\Cloudflare\Cloudflare;
use App\Enums\Servers\OperatingSystem;
use App\Exceptions\Providers\UnknownProvider;
use App\Models\DnsProvider;
use App\Models\ServerProvider;
use App\Plugins\PluginContext;
use App\Providers\DnsProviderServiceProvider;
use App\ServerProviders\Contracts\ServerDriver;
use App\ServerProviders\Drivers\Vultr\Vultr;
use App\Services\Plugins\PluginLoader;

it('registers every built-in provider with an icon on disk', function (string $model, array $ids) {
    $providers = $model::providers();

    expect(array_keys($providers))->toBe($ids)
        ->and(collect($providers)->every(fn (DnsDriver|ServerDriver $provider): bool => is_file(public_path((string) $provider->config('icon')))))->toBeTrue();
})->with([
    'dns' => [DnsProvider::class, ['cloudflare']],
    'server' => [ServerProvider::class, ['digitalocean', 'hetzner', 'vultr', 'linode']],
]);

it('lets each model build its driver around itself', function () {
    expect((new DnsProvider(['type' => 'cloudflare', 'credentials' => ['token' => 't']]))->provider())->toBeInstanceOf(Cloudflare::class)
        ->and((new ServerProvider(['type' => 'vultr', 'credentials' => ['token' => 't']]))->provider())->toBeInstanceOf(Vultr::class);
});

it('boots again over a config cache that already holds the providers', function () {
    resolve(PluginLoader::class)->reset();
    (new DnsProviderServiceProvider(app()))->boot();
    resolve(PluginLoader::class)->boot();

    expect(DnsProvider::types())->toBe(['cloudflare'])
        ->and(DnsProvider::providerFor('cloudflare')->form()->fields[0]->name)->toBe('token');
});

it('refuses a provider nobody registered', function () {
    expect(fn () => (new ServerProvider(['type' => 'aws']))->provider())->toThrow(UnknownProvider::class, 'No provider is registered as "aws".')
        ->and((new ServerProvider(['type' => 'aws']))->label())->toBe('aws (not installed)');
});

it('picks up a provider registered later, without touching the built-ins', function () {
    (new PluginContext('assets'))->server('acme')->label('Acme Cloud')->handler(Vultr::class)->images(['debian-13' => 'acme-debian'])->register();

    expect(ServerProvider::providerFor('acme')->toArray())->toMatchArray(['value' => 'acme', 'label' => 'Acme Cloud', 'icon' => asset('assets/icons/server-providers/acme.svg')])
        ->and(OperatingSystem::Debian13->toArray()['providers'])->toBe(['digitalocean', 'hetzner', 'vultr', 'linode', 'acme'])
        ->and(OperatingSystem::Debian12->toArray()['providers'])->toBe(['hetzner', 'vultr', 'linode']);
});
