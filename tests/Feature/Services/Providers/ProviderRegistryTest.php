<?php

declare(strict_types=1);

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\DatabaseProviders\Drivers\Postgres\Postgres;
use App\DnsProviders\Contracts\DnsDriver;
use App\DnsProviders\Drivers\Cloudflare\Cloudflare;
use App\Enums\Servers\OperatingSystem;
use App\Exceptions\Providers\UnknownProvider;
use App\Models\Database;
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
        ->and(collect($providers)->every(fn (DnsDriver|ServerDriver|DatabaseDriver $provider): bool => is_file(public_path((string) $provider->config('icon')))))->toBeTrue();
})->with([
    'dns' => [DnsProvider::class, ['cloudflare']],
    'server' => [ServerProvider::class, ['digitalocean', 'hetzner', 'vultr', 'linode']],
    'database' => [Database::class, ['valkey', 'redis', 'mysql', 'postgres', 'mariadb', 'mongodb']],
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
        ->and((new ServerProvider(['type' => 'aws']))->label())->toBe('aws (not installed)')
        ->and(fn () => Database::providerFor('oracle'))->toThrow(UnknownProvider::class, 'No provider is registered as "oracle".');
});

it('picks up a provider registered later, without touching the built-ins', function () {
    (new PluginContext('assets'))->server('acme')->label('Acme Cloud')->handler(Vultr::class)->images(['debian-13' => 'acme-debian'])->register();

    expect(ServerProvider::providerFor('acme')->toArray())->toMatchArray(['value' => 'acme', 'label' => 'Acme Cloud', 'icon' => asset('assets/icons/server-providers/acme.svg')])
        ->and(OperatingSystem::Debian13->toArray()['providers'])->toBe(['digitalocean', 'hetzner', 'vultr', 'linode', 'acme'])
        ->and(OperatingSystem::Debian12->toArray()['providers'])->toBe(['hetzner', 'vultr', 'linode']);
});

it('lets a database build its engine driver around itself', function () {
    $database = new Database(['engine' => 'postgres']);

    expect($database->provider())->toBeInstanceOf(Postgres::class)
        ->and(Database::providerFor('postgres'))->toBeInstanceOf(Postgres::class)
        ->and(Postgres::id())->toBe('postgres');
});

it('reads the fixed settings of an engine from its registration, so a plugin can reuse a driver under its own id', function () {
    (new PluginContext('assets'))->database('keydb')->label('KeyDB')->handler(Postgres::class)->port(6380)->peerPorts([16380])->persistenceChoice()->register();
    $keydb = Database::providerFor('keydb');
    $mysql = Database::providerFor('mysql');

    expect([$keydb->directory(), $keydb->systemUser(), $keydb->ports()->client, $keydb->ports()->pool, $keydb->ports()->peers, $keydb->supportsPersistence()])->toBe(['/srv/keydb', 'keydb', 6380, null, [16380], true])
        ->and($keydb->tls()->files)->toBe(['ca.crt', 'server.crt', 'server.key'])
        ->and([$mysql->ports()->client, $mysql->ports()->peers, $mysql->tls()->rsaKey, $mysql->tls()->publicCertificate])->toBe([25060, [33061], true, false])
        ->and(Database::providerFor('mariadb')->systemUser())->toBe('mysql')
        ->and(Database::providerFor('postgres')->ports()->pool)->toBe(25061);
});
