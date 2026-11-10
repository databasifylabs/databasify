<?php

declare(strict_types=1);

use App\Actions\Databases\Flows\StartFlow;
use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\Actions\Databases\Nodes\RebootDatabase;
use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\DTOs\Databases\NewDatabaseNode;
use App\Engines\Flows\CreateDatabaseFlow;
use App\Engines\Flows\FlowContext;
use App\Engines\Flows\RedeployNodeFlow;
use App\Enums\Databases\FlowState;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Server;
use Tests\Support\FakeServerConnection;

/**
 * @return list<string>
 */
function sentTo(Server $server, FakeServerConnection $ssh): array
{
    return array_values(array_map(fn (string $command): string => str($command)->after(': ')->value(), array_filter(engineCommandsIn($ssh), fn (string $command): bool => str_starts_with($command, "{$server->name}: "))));
}

function requestOf(FakeServerConnection $ssh, Server $server, string $command): array
{
    $index = collect($ssh->runs)->keys()->first(fn (int $index): bool => $ssh->runs[$index]['server'] === $server->name && str_contains($ssh->scriptAt($index), "| databasify {$command} "));

    return json_decode($ssh->engineRequestAt($index), true);
}

function failingOperationOn(Server $server, int $call): Closure
{
    $calls = 0;

    return function (string $name, string $script) use ($server, $call, &$calls): bool {
        if ($name !== $server->name || ! str_contains($script, '| databasify operation status ')) {
            return false;
        }

        return ++$calls === $call;
    };
}

function addThroughFlow(Database $database, Server $server, NodeKind $kind = NodeKind::Member): void
{
    resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server, $kind, $kind === NodeKind::ReadOnly ? 'reports' : null));
    advanceFlows();
}

it('adds a node in the documented order', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $existing = $database->nodes->map->server;
    $server = Server::factory()->ready()->create();

    addThroughFlow($database, $server);
    $commands = engineCommandsIn($ssh);

    expect(sentTo($server, $ssh))->toBe(['host deploy', 'tls install', 'node configure', 'node start', 'node ready', 'operation status', 'cluster enable', 'operation status', 'node status'])
        ->and($existing->map(fn (Server $peer): array => sentTo($peer, $ssh))->all())->each->toBe(['node configure', 'cluster membership', 'node status'])
        ->and(array_search("{$existing[0]->name}: cluster membership", $commands, true))->toBeGreaterThan(array_search("{$server->name}: node ready", $commands, true))
        ->and(count(requestOf($ssh, $existing[0], 'cluster membership')['members']))->toBe(3)
        ->and($database->flows()->latest('id')->where('flow', '!=', CreateDatabaseFlow::class)->sole()->state)->toBe(FlowState::Succeeded)
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->nodes()->count())->toBe(3);
});

it('enables the cluster on the seed first when a single node gets its first peer', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 1);
    $seed = $database->seed()->server;

    addThroughFlow($database, Server::factory()->ready()->create());

    expect(sentTo($seed, $ssh))->toBe(['node configure', 'cluster membership', 'cluster enable', 'operation status', 'node status']);
});

it('removes the new node and puts the old members back when the copy fails', function () {
    $server = Server::factory()->ready()->create();
    $ssh = fakeEngine(fakeSsh()->respondWhen(failingOperationOn($server, 2), json_encode(['key' => 'k', 'kind' => 'cluster enable', 'state' => 'failed', 'phase' => 'copying', 'progress' => null, 'startedAt' => '2026-11-10T00:00:00Z', 'finishedAt' => null, 'error' => ['code' => 'internal', 'message' => 'the copy broke', 'details' => (object) []]])));
    $database = idleDatabase('mysql', 2);
    $existing = $database->nodes->map->server;

    addThroughFlow($database, $server);

    expect(sentTo($server, $ssh))->toContain('host remove')
        ->and(requestOf($ssh, $server, 'host remove'))->toBe(['databaseId' => $database->id, 'erase' => true])
        ->and(sentTo($existing[0], $ssh))->toBe(['node configure', 'cluster membership', 'node configure', 'cluster membership', 'node status'])
        ->and($database->nodes()->count())->toBe(2)
        ->and($database->fresh()->operation)->toBeNull();
});

it('removes a node in the documented order', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $staying, $leaving] = $database->nodes()->orderBy('position')->get()->all();

    resolve(RemoveDatabaseNode::class)->handle($leaving);
    advanceFlows();

    expect(sentTo($leaving->server, $ssh))->toBe(['cluster disable', 'host remove'])
        ->and(sentTo($primary->server, $ssh))->toBe(['node configure', 'member forget', 'cluster membership', 'node status'])
        ->and(requestOf($ssh, $staying->server, 'member forget'))->toBe(['ip' => $leaving->privateIp()])
        ->and(count(requestOf($ssh, $staying->server, 'cluster membership')['members']))->toBe(2)
        ->and(DatabaseNode::query()->find($leaving->id))->toBeNull();
});

it('falls back to node reset when member forget is unsupported', function () {
    $ssh = fakeEngine(fakeSsh()->respondToEngine('member forget', ['error' => ['code' => 'unsupported', 'message' => 'use node reset', 'details' => (object) []]], exit: 1));
    $database = idleDatabase('valkey', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $staying, $leaving] = $database->nodes()->orderBy('position')->get()->all();

    resolve(RemoveDatabaseNode::class)->handle($leaving);
    advanceFlows();

    expect(sentTo($staying->server, $ssh))->toBe(['node configure', 'member forget', 'node reset', 'cluster membership', 'node status'])
        ->and(requestOf($ssh, $staying->server, 'node reset'))->toBe(['peers' => 1, 'hosts' => [$primary->privateIp(), $staying->privateIp()]]);
});

it('fails over before removing the primary', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();

    resolve(RemoveDatabaseNode::class)->handle($primary);
    advanceFlows();

    expect(sentTo($primary->server, $ssh))->toBe(['cluster failover', 'cluster disable', 'host remove'])
        ->and(requestOf($ssh, $primary->server, 'cluster failover'))->toBe(['target' => $standby->privateIp()])
        ->and(DatabaseNode::query()->find($primary->id))->toBeNull();
});

it('redeploys a cluster member and rejoins it', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $standby = $database->nodes()->orderBy('position')->get()[1];

    resolve(StartFlow::class)->handle($database, resolve(RedeployNodeFlow::class), fn (): FlowContext => new FlowContext([$standby->id], $standby->id));
    advanceFlows();

    expect(sentTo($standby->server, $ssh))->toBe(['host deploy', 'tls install', 'node ready', 'operation status', 'cluster rejoin', 'operation status', 'node status']);
});

it('reboots standbys first, fails over, then reboots the former primary', function () {
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    $ssh = fakeEngine(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $standby->server, 'replica'))->bootIdChangesAfterReboot();

    resolve(RebootDatabase::class)->handle($database);
    advanceFlows(40);
    $commands = engineCommandsIn($ssh);
    $rebootOf = fn (Server $server): int|false => collect($ssh->runs)->search(fn (array $run): bool => $run['server'] === $server->name && str_contains($run['script'], 'sleep 2; reboot'));

    expect(sentTo($standby->server, $ssh))->toContain('node ready')->toContain('cluster rejoin')
        ->and($rebootOf($standby->server))->toBeLessThan($rebootOf($primary->server))
        ->and(array_search("{$primary->server->name}: cluster failover", $commands, true))->toBeGreaterThan(array_search("{$standby->server->name}: cluster rejoin", $commands, true))
        ->and(requestOf($ssh, $primary->server, 'cluster failover'))->toBe(['target' => $standby->privateIp()])
        ->and(sentTo($primary->server, $ssh))->toContain('cluster rejoin')
        ->and($database->fresh()->operation)->toBeNull();
});
