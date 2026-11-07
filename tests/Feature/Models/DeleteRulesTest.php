<?php

declare(strict_types=1);

use App\Actions\Dns\DeleteDnsProvider;
use App\Actions\Networks\DeleteNetwork;
use App\Actions\Projects\DeleteProject;
use App\Actions\Providers\DeleteServerProvider;
use App\Actions\Servers\DeleteServer;
use App\Actions\Storage\DeleteStorageProvider;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\PoolMode;
use App\Enums\Databases\ResourceMetric;
use App\Enums\Migrations\MigrationMode;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Network;
use App\Models\NodeAlert;
use App\Models\NodeMetricSample;
use App\Models\Passkey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerLog;
use App\Models\ServerProvider;
use App\Models\StorageProvider;
use App\Models\User;
use Database\Factories\ProjectFactory;
use Illuminate\Validation\ValidationException;

it('deletes the passkeys of a deleted user', function () {
    $user = User::factory()->create();
    $passkey = $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'credential', 'credential' => ['type' => 'public-key']]);

    $user->delete();

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
});

it('refuses to delete a server provider that still has networks in any project', function () {
    $provider = ServerProvider::factory()->create(['name' => 'hetzner']);
    Network::factory()->create(['server_provider_id' => $provider->id, 'project_id' => Project::factory()->create()->id]);
    inProject();

    expect(fn () => resolve(DeleteServerProvider::class)->handle($provider))->toThrow(ValidationException::class, 'hetzner still has 1 network in Databasify. Delete them first.')
        ->and($provider->fresh())->not->toBeNull();
});

it('refuses to delete a server provider that still has servers in any project', function () {
    $provider = ServerProvider::factory()->create(['name' => 'hetzner']);
    Server::factory()->create(['server_provider_id' => $provider->id, 'project_id' => Project::factory()->create()->id]);
    inProject();

    expect(fn () => resolve(DeleteServerProvider::class)->handle($provider))->toThrow(ValidationException::class, 'hetzner still has 1 server in Databasify. Delete them first.')
        ->and($provider->fresh())->not->toBeNull();
});

it('refuses to delete a network that servers in any project still use', function () {
    $network = Network::factory()->create(['name' => 'private-1']);
    Server::factory()->onNetwork($network)->create(['project_id' => Project::factory()->create()->id]);
    inProject();

    expect(fn () => resolve(DeleteNetwork::class)->handle($network))->toThrow(ValidationException::class, 'private-1 still has servers on it.')
        ->and($network->fresh())->not->toBeNull();
});

it('deletes a network that no server uses', function () {
    $network = Network::factory()->create();

    $network->delete();

    expect($network->fresh())->toBeNull();
});

it('deletes the zones of a deleted dns provider', function () {
    $zone = DnsZone::factory()->create();

    $zone->provider->delete();

    expect($zone->fresh())->toBeNull();
});

it('refuses to delete a dns provider whose domain a database uses', function () {
    $database = withZone(idleDatabase('valkey', 1, DatabaseMode::Single));
    $provider = DnsProvider::query()->sole();
    $provider->update(['name' => 'cloudflare']);

    expect(fn () => resolve(DeleteDnsProvider::class)->handle($provider))->toThrow(ValidationException::class, 'main uses a domain from cloudflare, pick another domain for it first.')
        ->and($database->dnsZone?->fresh())->not->toBeNull();
});

it('refuses to delete a storage provider that a database archives to', function () {
    $provider = StorageProvider::factory()->create(['name' => 'bucket']);
    withRecovery(idleDatabase('valkey', 1, DatabaseMode::Single), provider: $provider);

    expect(fn () => resolve(DeleteStorageProvider::class)->handle($provider))->toThrow(ValidationException::class, 'main archives to bucket, turn off its point-in-time recovery first.');
});

it('deletes the users and logs of a deleted server', function () {
    $server = Server::factory()->create();
    $user = $server->users()->create(['username' => 'deploy']);
    $log = ServerLog::factory()->for($server)->create();

    $server->delete();

    expect($user->fresh())->toBeNull()
        ->and($log->fresh())->toBeNull();
});

it('refuses to delete a server that is part of a database', function () {
    $server = idleDatabase('valkey', 1, DatabaseMode::Single)->seed()->server;

    expect(fn () => resolve(DeleteServer::class)->handle($server))->toThrow(ValidationException::class, "{$server->name} is part of a database, remove it from the database first.")
        ->and($server->fresh())->not->toBeNull();
});

it('deletes the children of a deleted database and keeps its server logs', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single));
    $node = $database->seed();
    sampleCpu($node, 10, 20);
    $alert = $node->raiseAlert(ResourceMetric::Cpu);
    $migration = migrationOf($database, MigrationMode::OneTime);
    $children = [
        $database->users()->create(['name' => 'reporting', 'password' => 'secret-pass']),
        $database->schemas()->create(['name' => 'reports']),
        $database->trustedSources()->create(['cidr' => '203.0.113.0/24']),
        $database->connectionPools()->create(['name' => 'reports', 'schema' => 'reports', 'mode' => PoolMode::Transaction, 'size' => 10]),
        $database->pointInTimeRecovery,
    ];
    $log = ServerLog::factory()->for($node->server)->create(['database_id' => $database->id]);

    $database->delete();

    expect(collect([$node, $alert, $migration, ...$children])->map->fresh()->filter()->all())->toBe([])
        ->and(NodeMetricSample::query()->where('database_node_id', $node->id)->exists())->toBeFalse()
        ->and($log->fresh()?->database_id)->toBeNull();
});

it('deletes the samples and alerts of a deleted node and detaches its migrations', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $node = $database->seed();
    sampleCpu($node, 10);
    $alert = $node->raiseAlert(ResourceMetric::Cpu);
    $migration = migrationOf($database, MigrationMode::OneTime);

    $node->delete();

    expect(NodeMetricSample::query()->where('database_node_id', $node->id)->exists())->toBeFalse()
        ->and(NodeAlert::query()->whereKey($alert->id)->exists())->toBeFalse()
        ->and($migration->fresh()?->database_node_id)->toBeNull();
});

it('forgets a deleted project as the last project of its visitors', function () {
    ProjectFactory::defaultProject();
    $project = Project::factory()->create();
    $user = User::factory()->create(['last_project_id' => $project->id]);

    $project->delete();

    expect($project->fresh())->toBeNull()
        ->and($user->fresh()?->last_project_id)->toBeNull();
});

it('refuses to delete the last project', function () {
    $project = ProjectFactory::defaultProject();

    expect(fn () => resolve(DeleteProject::class)->handle($project))->toThrow(ValidationException::class, 'The last project cannot be deleted.');
});

it('refuses to delete a project that still has resources', function () {
    $project = ProjectFactory::defaultProject();
    $project->update(['name' => 'Default']);
    Project::factory()->create();
    Server::factory()->create();

    expect(fn () => resolve(DeleteProject::class)->handle($project))->toThrow(ValidationException::class, 'Default still has servers, databases, networks or providers. Move or delete them first.');
});
