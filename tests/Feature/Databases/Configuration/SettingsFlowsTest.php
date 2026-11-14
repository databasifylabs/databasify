<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\RenewCertificates;
use App\Actions\Databases\Configuration\RetuneDatabase;
use App\Actions\Databases\Configuration\UpdateDatabaseConfig;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Models\Server;
use App\Models\User;
use Tests\Support\FakeServerConnection;

/**
 * @return list<string>
 */
function commandsSentTo(Server $server, FakeServerConnection $ssh): array
{
    return collect(engineCommandsIn($ssh))
        ->filter(fn (string $command): bool => str_starts_with($command, "{$server->name}: "))
        ->map(fn (string $command): string => str($command)->after(': ')->value())
        ->values()
        ->all();
}

it('rotates certificates with tls install on every node', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 2);

    resolve(RenewCertificates::class)->handle($database);

    $database->refresh();

    expect(collect(recordedArgs('nodes.installTls'))->pluck('server')->sort()->values()->all())->toBe($database->nodes->map->server->pluck('name')->sort()->values()->all())
        ->and($database->allCertificatesCurrent())->toBeTrue();
});

it("opens the engine's own peer ports", function () {
    $ssh = fakeSsh();
    $database = idleDatabase('mariadb', 2);

    resolve(ApplyNodeFirewall::class)->handle($database, $database->seed(), []);

    expect($ssh->scriptAt(count($ssh->runs) - 1))->toContain('4444')->toContain('4567')->toContain('4568')->toContain('25060');
});

it('applies settings with config sync once and config apply on every node', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->map->server->all();

    resolve(UpdateDatabaseConfig::class)->handle($database, ['max_connections' => '200']);

    expect(commandsSentTo($primary, $ssh))->toBe(['nodes.configure', 'config.sync', 'config.apply', 'config.pendingRestart', 'task.status', 'nodes.status'])
        ->and(commandsSentTo($standby, $ssh))->toBe(['nodes.configure', 'config.apply', 'task.status', 'nodes.status'])
        ->and($database->fresh()->settings->config)->toBe(['max_connections' => '200'])
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('rolls a restart through standbys, failover and the former primary, then restarts the read-only node on its own', function () {
    $ssh = fakeEngine();
    answerRead('config.pendingRestart', ['max_connections']);
    $database = idleDatabase('postgres', 2);
    $reader = withReadOnlyNode($database);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->map->server->all();

    resolve(UpdateDatabaseConfig::class)->handle($database->fresh(), ['max_connections' => '300']);
    $commands = engineCommandsIn($ssh);
    $at = fn (Server $server, string $command): int|false => array_search("{$server->name}: {$command}", $commands, true);

    expect($at($standby, 'nodes.restart'))->toBeLessThan($at($primary, 'cluster.failover'))
        ->and($at($primary, 'cluster.failover'))->toBeLessThan($at($primary, 'nodes.restart'))
        ->and($at($primary, 'nodes.restart'))->toBeLessThan($at($reader->server, 'nodes.restart'))
        ->and($reader->fresh()->config)->toBe([])
        ->and(commandsSentTo($primary, $ssh))->toContain('cluster.rejoin')
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready)
        ->and($reader->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('validates settings against the stored config fields', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('postgres', 1);

    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['max_connections' => '5']])->assertSessionHasErrors(['config.max_connections' => 'Max connections must be between 10 and 10000.']);
    $this->put(projectUrl("/databases/{$database->id}/config"), ['config' => ['made_up' => '1']])->assertSessionHasErrors('config');
});

it('retunes every node and restarts only when the engine asks for it', function () {
    $database = idleDatabase('postgres', 1);
    $ssh = fakeEngine(fakeSsh()->respondWhen(fn (string $server, string $script): bool => str_contains($script, '/etc/os-release'), '{"os":"Ubuntu 24.04","id":"ubuntu","version_id":"24.04","cpus":2,"memory_mb":4096,"disk_gb":80}'."\n"));

    resolve(RetuneDatabase::class)->handle($database);

    expect(commandsSentTo($database->seed()->server, $ssh))->toBe(['nodes.configure', 'config.tune', 'config.apply', 'task.status', 'nodes.status'])
        ->and($database->fresh()->retune_needed_at)->toBeNull();
});
