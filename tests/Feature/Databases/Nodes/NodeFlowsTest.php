<?php

declare(strict_types=1);

use App\Actions\Databases\Flows\StartFlow;
use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\Actions\Databases\Nodes\RebootDatabase;
use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\Actions\Databases\Upgrades\UpgradeDatabase;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NewDatabaseNode;
use App\Engines\Data\EngineField;
use App\Engines\Data\Operation;
use App\Engines\Flows\CreateDatabaseFlow;
use App\Engines\Flows\FlowContext;
use App\Engines\Flows\RedeployNodeFlow;
use App\Engines\Payload;
use App\Enums\Databases\FlowState;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\OperationState;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Server;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeServerConnection;

/**
 * @return list<string>
 */
function sentTo(Server $server, FakeServerConnection $ssh): array
{
    return array_values(array_map(fn (string $command): string => str($command)->after(': ')->value(), array_filter(engineCommandsIn($ssh), fn (string $command): bool => str_starts_with($command, "{$server->name}: "))));
}

/**
 * @return list<mixed>
 */
function argsOn(Server $server, string $call): array
{
    return collect(recordedArgs($call))->firstWhere('server', $server->name)['args'];
}

function failingTaskOn(Server $server, int $call): void
{
    $calls = 0;

    answerRead('task.status', function (string $key) use ($call, &$calls): Operation {
        return ++$calls === $call
            ? new Operation($key, 'task', OperationState::Failed, 'copying', null, 'the copy broke')
            : new Operation($key, 'task', OperationState::Succeeded, '', null, null);
    }, $server);
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

    expect(sentTo($server, $ssh))->toBe(['host deploy', 'engine info', 'nodes.installTls', 'nodes.configure', 'nodes.start', 'nodes.ready', 'task.status', 'cluster.enable', 'task.status', 'nodes.status'])
        ->and($existing->map(fn (Server $peer): array => sentTo($peer, $ssh))->all())->each->toBe(['nodes.configure', 'cluster.membership', 'nodes.status'])
        ->and(array_search("{$existing[0]->name}: cluster.membership", $commands, true))->toBeGreaterThan(array_search("{$server->name}: nodes.ready", $commands, true))
        ->and(argsOn($existing[0], 'cluster.membership')[0])->toHaveCount(3)
        ->and($database->flows()->latest('id')->where('flow', '!=', CreateDatabaseFlow::class)->sole()->state)->toBe(FlowState::Succeeded)
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->nodes()->count())->toBe(3);
});

it('opens the firewall of every member before configuring the new node', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $existing = $database->nodes->map->server;
    $server = Server::factory()->ready()->create();

    addThroughFlow($database, $server);
    $runs = collect($ssh->runs);
    $firewallOn = fn (Server $peer): int|false => $runs->search(fn (array $run): bool => $run['server'] === $peer->name && str_contains($run['script'], '/etc/databasify/nftables/'));
    $configure = collect(useRecordingDrivers()->calls)->first(fn (array $call): bool => $call['server'] === $server->name && $call['call'] === 'nodes.configure')['at'];

    expect($existing->map($firewallOn)->all())->each->toBeLessThan($configure);
});

it('enables the cluster on the seed first when a single node gets its first peer', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 1);
    $seed = $database->seed()->server;

    addThroughFlow($database, Server::factory()->ready()->create());

    expect(sentTo($seed, $ssh))->toBe(['nodes.configure', 'cluster.membership', 'cluster.enable', 'task.status', 'nodes.status']);
});

it('removes the new node and puts the old members back when the copy fails', function () {
    $server = Server::factory()->ready()->create();
    $ssh = fakeEngine();
    failingTaskOn($server, 2);
    $database = idleDatabase('mysql', 2);
    $existing = $database->nodes->map->server;

    addThroughFlow($database, $server);

    expect(sentTo($server, $ssh))->toContain('host remove')
        ->and(json_decode($ssh->engineRequestAt(collect($ssh->runs)->search(fn (array $run): bool => $run['server'] === $server->name && str_contains($run['script'], '| databasify host remove '))), true))->toBe(['databaseId' => $database->id, 'erase' => true])
        ->and(sentTo($existing[0], $ssh))->toBe(['nodes.configure', 'cluster.membership', 'nodes.configure', 'cluster.membership', 'nodes.status'])
        ->and($database->nodes()->count())->toBe(2)
        ->and($database->fresh()->operation)->toBeNull();
});

it('removes a node in the documented order', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $staying, $leaving] = $database->nodes()->orderBy('position')->get()->all();
    $database->nodes()->orderBy('position')->get()->each(fn (DatabaseNode $node, int $index) => answerNodeStatus($ssh, $node->server, $index === 0 ? 'primary' : 'replica'));

    resolve(RemoveDatabaseNode::class)->handle($leaving);
    advanceFlows();

    expect(sentTo($leaving->server, $ssh))->toBe(['nodes.status', 'cluster.disable', 'host remove'])
        ->and(sentTo($primary->server, $ssh))->toBe(['nodes.status', 'nodes.configure', 'cluster.forget', 'cluster.membership', 'nodes.status'])
        ->and(argsOn($staying->server, 'cluster.forget'))->toBe([$leaving->privateIp()])
        ->and(argsOn($staying->server, 'cluster.membership')[0])->toHaveCount(2)
        ->and(DatabaseNode::query()->find($leaving->id))->toBeNull();
});

it('falls back to node reset when member forget is unsupported', function () {
    $ssh = fakeEngine();
    answerRead('cluster.forget', fn () => throw EngineUnsupported::command('member forget'));
    $database = idleDatabase('valkey', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $staying, $leaving] = $database->nodes()->orderBy('position')->get()->all();
    $database->nodes()->orderBy('position')->get()->each(fn (DatabaseNode $node, int $index) => answerNodeStatus($ssh, $node->server, $index === 0 ? 'primary' : 'replica'));

    resolve(RemoveDatabaseNode::class)->handle($leaving);
    advanceFlows();

    expect(sentTo($staying->server, $ssh))->toBe(['nodes.status', 'nodes.configure', 'cluster.forget', 'nodes.reset', 'cluster.membership', 'nodes.status'])
        ->and(argsOn($staying->server, 'nodes.reset'))->toBe([1, [$primary->privateIp(), $staying->privateIp()]]);
});

it('fails over before removing the primary', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    $database->nodes()->orderBy('position')->get()->each(fn (DatabaseNode $node, int $index) => answerNodeStatus($ssh, $node->server, $index === 0 ? 'primary' : 'replica'));

    resolve(RemoveDatabaseNode::class)->handle($primary);
    advanceFlows();

    expect(sentTo($primary->server, $ssh))->toBe(['nodes.status', 'cluster.failover', 'cluster.disable', 'host remove'])
        ->and(argsOn($primary->server, 'cluster.failover'))->toBe([$standby->privateIp()])
        ->and(DatabaseNode::query()->find($primary->id))->toBeNull();
});

it('redeploys a cluster member and rejoins it', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $standby = $database->nodes()->orderBy('position')->get()[1];

    resolve(StartFlow::class)->handle($database, resolve(RedeployNodeFlow::class), fn (): FlowContext => new FlowContext([$standby->id], $standby->id));
    advanceFlows();

    expect(sentTo($standby->server, $ssh))->toBe(['host deploy', 'engine info', 'nodes.installTls', 'nodes.ready', 'task.status', 'cluster.rejoin', 'task.status', 'nodes.status']);
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

    expect(sentTo($standby->server, $ssh))->toContain('nodes.ready')->toContain('cluster.rejoin')
        ->and($rebootOf($standby->server))->toBeLessThan($rebootOf($primary->server))
        ->and(array_search("{$primary->server->name}: cluster.failover", $commands, true))->toBeGreaterThan(array_search("{$standby->server->name}: cluster.rejoin", $commands, true))
        ->and(argsOn($primary->server, 'cluster.failover'))->toBe([$standby->privateIp()])
        ->and(sentTo($primary->server, $ssh))->toContain('cluster.rejoin')
        ->and($database->fresh()->operation)->toBeNull();
});

it('upgrades in the documented order and reads the engine again afterwards', function () {
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    $ssh = fakeEngine(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $standby->server, 'replica'));
    $offered = new EngineImage($database->image->repository, 'sha256:'.str_repeat('b', 64), '8.4.9', '0.2.4');
    $database->update(['available_image' => $offered, 'config_fields' => []]);
    answerRead('config.fields', [EngineField::fromPayload(new Payload(engineFieldPayload()))]);

    resolve(UpgradeDatabase::class)->handle($database->fresh());
    advanceFlows(40);
    $commands = engineCommandsIn($ssh);
    $at = fn (Server $server, string $command): int|false => array_search("{$server->name}: {$command}", $commands, true);

    expect($at($standby->server, 'host deploy'))->toBeLessThan($at($primary->server, 'cluster.failover'))
        ->and($at($primary->server, 'cluster.failover'))->toBeLessThan($at($primary->server, 'host deploy'))
        ->and($at($primary->server, 'host deploy'))->toBeLessThan($at($primary->server, 'nodes.configure'))
        ->and($at($primary->server, 'nodes.configure'))->toBeLessThan($at($primary->server, 'nodes.ready'))
        ->and($at($standby->server, 'host deploy'))->toBeLessThan($at($standby->server, 'nodes.configure'))
        ->and($at($standby->server, 'nodes.configure'))->toBeLessThan($at($standby->server, 'nodes.ready'))
        ->and(json_decode($ssh->engineRequestAt(collect($ssh->runs)->search(fn (array $run): bool => $run['server'] === $primary->server->name && str_contains($run['script'], '| databasify host deploy '))), true)['image'])->toEndWith('@sha256:'.str_repeat('b', 64))
        ->and($database->fresh())->image->toEqual($offered)->available_image->toBeNull()->operation->toBeNull()
        ->and($database->fresh()->config_fields)->toHaveCount(1);
});

it('refuses to install an offered image built on an SDK outside the range', function () {
    $database = idleDatabase('mysql', 1);
    $ssh = fakeEngine();
    $database->update(['available_image' => new EngineImage($database->image->repository, 'sha256:'.str_repeat('b', 64), '8.4.9', '0.3.0')]);
    $image = $database->image;

    expect(fn () => resolve(UpgradeDatabase::class)->handle($database->fresh()))
        ->toThrow(ValidationException::class, 'MySQL 8.4.9 is built on SDK 0.3.0; this Databasify supports ^0.2.')
        ->and($database->fresh())->image->toEqual($image)->operation->toBeNull()
        ->and($ssh->runs)->toBe([]);
});

it('refuses to reboot while a node has just stopped answering', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $standby, $silent] = $database->nodes()->orderBy('position')->get()->all();
    answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $standby->server, 'replica'), $silent->server, null);

    expect(fn () => resolve(RebootDatabase::class)->handle($database))->toThrow(ValidationException::class, "{$silent->server->name} does not answer");
});
