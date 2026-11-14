<?php

declare(strict_types=1);

use App\Actions\Databases\Certificates\RenewCertificates;
use App\Actions\Databases\Configuration\RetuneDatabase;
use App\Actions\Databases\Configuration\UpdateDatabaseConfig;
use App\Actions\Databases\Network\ApplyNodeFirewall;
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
    advanceFlows();

    expect(commandsSentTo($primary, $ssh))->toBe(['nodes.configure', 'config.sync', 'config.apply', 'config.pendingRestart', 'nodes.status'])
        ->and(commandsSentTo($standby, $ssh))->toBe(['nodes.configure', 'config.apply', 'nodes.status'])
        ->and($database->fresh()->settings->config)->toBe(['max_connections' => '200'])
        ->and($database->fresh()->operation)->toBeNull();
});

it('rolls a restart through read-only, standbys, failover, then the former primary', function () {
    $ssh = fakeEngine();
    answerRead('config.pendingRestart', ['max_connections']);
    $database = idleDatabase('postgres', 2);
    $reader = withReadOnlyNode($database);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->map->server->all();

    resolve(UpdateDatabaseConfig::class)->handle($database->fresh(), ['max_connections' => '300']);
    advanceFlows(40);
    $commands = engineCommandsIn($ssh);
    $at = fn (Server $server, string $command): int|false => array_search("{$server->name}: {$command}", $commands, true);

    expect($at($reader->server, 'nodes.restart'))->toBeLessThan($at($standby, 'nodes.restart'))
        ->and($at($standby, 'nodes.restart'))->toBeLessThan($at($primary, 'cluster.failover'))
        ->and($at($primary, 'cluster.failover'))->toBeLessThan($at($primary, 'nodes.restart'))
        ->and(commandsSentTo($primary, $ssh))->toContain('cluster.rejoin')
        ->and($database->fresh()->operation)->toBeNull();
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
    advanceFlows();

    expect(commandsSentTo($database->seed()->server, $ssh))->toBe(['nodes.configure', 'config.tune', 'config.apply', 'nodes.status'])
        ->and($database->fresh()->retune_needed_at)->toBeNull();
});
