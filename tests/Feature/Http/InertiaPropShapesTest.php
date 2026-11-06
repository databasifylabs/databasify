<?php

declare(strict_types=1);

use App\DTOs\Migrations\MigrationSource;
use App\Enums\Databases\PoolMode;
use App\Enums\Databases\ResourceMetric;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Models\Database;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Server;
use App\Models\StorageProvider;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function databaseWithEveryRelation(): Database
{
    $database = idleDatabase('postgres', 2);
    $database->update(['dns_zone_id' => DnsZone::factory()->for(DnsProvider::factory(), 'provider')->create()->id]);
    $database->trustedSources()->create(['cidr' => '10.0.0.0/24', 'label' => 'office']);
    $database->connectionPools()->create(['name' => 'web', 'schema' => 'main', 'mode' => PoolMode::cases()[0], 'size' => 5]);
    $database->migrations()->create(['mode' => MigrationMode::OneTime, 'status' => MigrationStatus::Completed, 'source' => new MigrationSource('db.example.com', 5432, 'migrator', 'secret')]);
    $database->seed()->alerts()->create(['metric' => ResourceMetric::cases()[0], 'opened_at' => now()]);

    return $database;
}

it('sends every nested database list as plain arrays with fixed keys', function () {
    $database = databaseWithEveryRelation();
    StorageProvider::factory()->create();

    $this->get("/databases/{$database->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('database.data', fn (AssertableInertia $data) => $data
            ->has('users.0', fn ($user) => $user->hasAll(['id', 'name', 'password', 'password_encryption', 'is_default']))
            ->has('schemas.0', fn ($schema) => $schema->hasAll(['id', 'name', 'is_default']))
            ->has('migrations.0', fn ($migration) => $migration->hasAll(['id', 'mode', 'status', 'source', 'detail', 'lag_seconds', 'started_at', 'finished_at', 'cleanup_failed']))
            ->has('alerts.0', fn ($alert) => $alert->hasAll(['id', 'node_id', 'node_name', 'metric', 'label', 'opened_at']))
            ->has('pools.0', fn ($pool) => $pool->hasAll(['id', 'name', 'schema', 'mode', 'size']))
            ->has('trusted_sources.0', fn ($source) => $source->hasAll(['id', 'cidr', 'label']))
            ->has('nodes', 2)
            ->has('network', fn ($network) => $network->hasAll(['id', 'name', 'kind', 'cidr', 'region']))
            ->where('point_in_time_recovery', null)
            ->etc())
        ->has('dnsZones.0', fn ($zone) => $zone->hasAll(['id', 'name', 'database_domain', 'provider']))
        ->has('storageProviders.0', fn ($provider) => $provider->hasAll(['id', 'name', 'bucket'])));
});

it('shares the list fields with the database page', function () {
    $database = databaseWithEveryRelation();
    $fields = ['id', 'name', 'engine_label', 'engine_icon', 'engine_version', 'available_version', 'available_update_refreshes_image', 'mode', 'status', 'operation', 'created_at', 'point_in_time_recovery', 'nodes'];

    $list = $this->get('/databases')->inertiaProps('databases.data.0');
    $show = $this->get("/databases/{$database->id}")->inertiaProps('database.data');

    expect(array_keys($list))->toEqualCanonicalizing($fields)
        ->and(array_intersect_key($show, array_flip($fields)))->toEqual($list);
});

it('lists the engines the create page offers', function () {
    $this->get('/databases/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('engines', count(Database::engines()))
        ->has('engines.0', fn ($engine) => $engine->hasAll(['value', 'label', 'icon', 'category', 'max_standbys', 'lines']))
        ->has('servers.data'));
});

it('lists server users as plain arrays', function () {
    $server = Server::factory()->ready()->create();
    $server->users()->create(['username' => 'app']);

    $this->get("/servers/{$server->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('users', 1)
        ->has('users.0', fn ($user) => $user->hasAll(['id', 'username'])));
});

it('lists passkeys with when they were created and last used', function () {
    $this->user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential', 'credential' => ['type' => 'public-key']]);

    $this->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('passkeys', 1)
        ->has('passkeys.0', fn ($passkey) => $passkey->hasAll(['id', 'name', 'authenticator', 'created_at_diff', 'last_used_at_diff'])
            ->where('name', 'Laptop')
            ->where('last_used_at_diff', null)));
});

it('describes what the dashboard requires', function () {
    $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('requirements', fn ($requirements) => $requirements->hasAll(['systems', 'engines'])));
});

it('offers every installed provider type on the add pages', function () {
    $this->get('/dns/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('types.0', fn ($type) => $type->hasAll(['value', 'label', 'icon', 'form', 'instructions'])));
    $this->get('/providers/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('types.0', fn ($type) => $type->hasAll(['value', 'label', 'icon', 'form', 'server_label', 'ssh_user', 'instructions'])));
});

it('describes a provider whose type is no longer installed', function () {
    DnsProvider::factory()->create(['type' => 'gone']);

    $this->get('/dns')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('providers.0.type', ['value' => 'gone', 'label' => 'gone (not installed)', 'icon' => null, 'form' => []]));
});
