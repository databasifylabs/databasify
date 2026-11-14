<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\AddDatabaseNode;
use App\Actions\Databases\Nodes\RebootDatabase;
use App\Actions\Databases\Nodes\RemoveDatabaseNode;
use App\Actions\Databases\Nodes\TakeNodeOut;
use App\Actions\Databases\Upgrades\UpgradeDatabase;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NewDatabaseNode;
use App\Engines\Data\EngineField;
use App\Engines\Data\Operation;
use App\Engines\Payload;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\OperationState;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\NotPrimary;
use App\Jobs\Databases\RedeployNodeJob;
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
    runOperation(fn () => resolve(AddDatabaseNode::class)->handle($database, new NewDatabaseNode($server, $kind, $kind === NodeKind::ReadOnly ? 'reports' : null)));
}

it('adds a node in the documented order', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $existing = $database->nodes->map->server;
    $server = Server::factory()->ready()->create();

    addThroughFlow($database, $server);
    $commands = engineCommandsIn($ssh);

    expect(sentTo($server, $ssh))->toBe(['task.status', 'host deploy', 'engine info', 'nodes.installTls', 'nodes.configure', 'nodes.start', 'nodes.ready', 'task.status', 'cluster.enable', 'task.status', 'nodes.status'])
        ->and($existing->map(fn (Server $peer): array => sentTo($peer, $ssh))->all())->each->toBe(['nodes.configure', 'cluster.membership', 'nodes.status'])
        ->and(array_search("{$existing[0]->name}: cluster.membership", $commands, true))->toBeGreaterThan(array_search("{$server->name}: nodes.ready", $commands, true))
        ->and(argsOn($existing[0], 'cluster.membership')[0])->toHaveCount(3)
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready)
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

it('turns a single postgresql node into a cluster before its first peer starts', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    markRoles($database, NodeRole::Primary);
    $seed = $database->seed()->server;
    $server = Server::factory()->ready()->create();

    addThroughFlow($database, $server);
    $commands = engineCommandsIn($ssh);

    expect(sentTo($seed, $ssh))->toBe(['nodes.configure', 'cluster.enable', 'task.status', 'nodes.configure', 'nodes.status'])
        ->and(array_search("{$seed->name}: cluster.enable", $commands, true))->toBeLessThan(array_search("{$server->name}: nodes.configure", $commands, true))
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready)
        ->and($database->nodes()->count())->toBe(2);
});

it('takes a single node back out of the cluster when its first peer fails to join', function (string $engine) {
    $server = Server::factory()->ready()->create();
    $ssh = fakeEngine();
    failingTaskOn($server, 2);
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $seed = $database->seed()->server;

    addThroughFlow($database, $server);

    expect(sentTo($seed, $ssh))->toBe(['nodes.configure', 'cluster.enable', 'task.status', 'nodes.configure', 'task.status', 'cluster.disable', 'nodes.status'])
        ->and($database->nodes()->count())->toBe(1)
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
})->with(['postgres', 'mariadb']);

it('leaves a single node standalone when its first peer fails before the node joined a cluster', function (string $engine) {
    $ssh = fakeEngine();
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $seed = $database->seed()->server;
    answerRead('cluster.enable', fn (): never => throw new EngineError('the seed could not start its cluster'), $seed);

    addThroughFlow($database, Server::factory()->ready()->create());

    expect(sentTo($seed, $ssh))->not->toContain('cluster.disable')
        ->and($database->nodes()->count())->toBe(1)
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
})->with(['postgres', 'mariadb']);

it('fails the database when a single node cannot leave the cluster after its first peer failed to join', function () {
    $server = Server::factory()->ready()->create();
    fakeEngine();
    failingTaskOn($server, 2);
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    answerRead('cluster.disable', fn (): never => throw new EngineError('PostgreSQL did not come back as a standalone primary'), $database->seed()->server);

    addThroughFlow($database, $server);

    expect($database->nodes()->count())->toBe(1)
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Failed);
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
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('removes a node in the documented order', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $staying, $leaving] = $database->nodes()->orderBy('position')->get()->all();
    $database->nodes()->orderBy('position')->get()->each(fn (DatabaseNode $node, int $index) => answerNodeStatus($ssh, $node->server, $index === 0 ? 'primary' : 'replica'));

    runOperation(fn () => resolve(RemoveDatabaseNode::class)->handle($leaving));

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

    runOperation(fn () => resolve(RemoveDatabaseNode::class)->handle($leaving));

    expect(sentTo($staying->server, $ssh))->toBe(['nodes.status', 'nodes.configure', 'cluster.forget', 'nodes.reset', 'cluster.membership', 'nodes.status'])
        ->and(argsOn($staying->server, 'nodes.reset'))->toBe([1, [$primary->privateIp(), $staying->privateIp()]]);
});

it('leaves the cluster instead of resetting it when one member remains', function () {
    $ssh = fakeEngine();
    answerRead('cluster.forget', fn () => throw EngineUnsupported::command('member forget'));
    $database = idleDatabase('valkey', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$staying, $leaving] = $database->nodes()->orderBy('position')->get()->all();
    $database->nodes()->orderBy('position')->get()->each(fn (DatabaseNode $node, int $index) => answerNodeStatus($ssh, $node->server, $index === 0 ? 'primary' : 'replica'));

    runOperation(fn () => resolve(RemoveDatabaseNode::class)->handle($leaving));

    expect(sentTo($staying->server, $ssh))->toBe(['nodes.status', 'nodes.configure', 'cluster.forget', 'cluster.disable', 'cluster.membership', 'nodes.status'])
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('restarts the remaining node on its own when a cluster shrinks to one member', function (string $engine) {
    $ssh = fakeEngine();
    $database = idleDatabase($engine, 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$staying, $leaving] = $database->nodes()->orderBy('position')->get()->all();
    $database->nodes()->orderBy('position')->get()->each(fn (DatabaseNode $node, int $index) => answerNodeStatus($ssh, $node->server, $index === 0 ? 'primary' : 'replica'));

    runOperation(fn () => resolve(RemoveDatabaseNode::class)->handle($leaving));

    expect(sentTo($staying->server, $ssh))->toBe(['nodes.status', 'nodes.status', 'nodes.configure', 'cluster.forget', 'cluster.disable', 'cluster.membership', 'nodes.status'])
        ->and(argsOn($staying->server, 'nodes.configure')[0]->clustered)->toBeFalse()
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready)
        ->and($database->nodes()->count())->toBe(1);
})->with(['postgres', 'mariadb']);

it('refuses to run a stale standby on its own when a cluster shrinks to one member', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$leaving, $staying] = $database->nodes()->orderBy('position')->get()->all();
    answerNodeStatus(answerNodeStatus($ssh, $leaving->server, null), $staying->server, 'replica');

    expect(fn () => resolve(TakeNodeOut::class)->handle($database, $leaving, null, true))->toThrow(NotPrimary::class, "{$staying->server->name} is not the primary");
    expect(sentTo($staying->server, $ssh))->toBe(['nodes.status'])
        ->and(sentTo($leaving->server, $ssh))->not->toContain('host remove')
        ->and($database->nodes()->count())->toBe(2);
});

it('fails the database when the last node cannot run on its own after a shrink', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$staying, $leaving] = $database->nodes()->orderBy('position')->get()->all();
    $database->nodes()->orderBy('position')->get()->each(fn (DatabaseNode $node, int $index) => answerNodeStatus($ssh, $node->server, $index === 0 ? 'primary' : 'replica'));
    answerRead('cluster.disable', fn (): never => throw new EngineError('PostgreSQL did not come back as a standalone primary'), $staying->server);

    runOperation(fn () => resolve(RemoveDatabaseNode::class)->handle($leaving));

    expect($database->fresh()->status)->toBe(DatabaseStatus::Failed);
});

it('fails over before removing the primary', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    $database->nodes()->orderBy('position')->get()->each(fn (DatabaseNode $node, int $index) => answerNodeStatus($ssh, $node->server, $index === 0 ? 'primary' : 'replica'));

    runOperation(fn () => resolve(RemoveDatabaseNode::class)->handle($primary));

    expect(sentTo($primary->server, $ssh))->toBe(['nodes.status', 'cluster.failover', 'cluster.disable', 'host remove'])
        ->and(argsOn($primary->server, 'cluster.failover'))->toBe([$standby->privateIp()])
        ->and(DatabaseNode::query()->find($primary->id))->toBeNull();
});

it('redeploys a cluster member and rejoins it', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $standby = $database->nodes()->orderBy('position')->get()[1];

    $database->markBusy(DatabaseStatus::Scaling);
    runOperation(fn () => dispatch(new RedeployNodeJob($database, $standby)));

    expect(sentTo($standby->server, $ssh))->toBe(['task.status', 'host deploy', 'engine info', 'nodes.installTls', 'nodes.ready', 'task.status', 'cluster.rejoin', 'task.status', 'nodes.status']);
});

it('reboots standbys first, fails over, then reboots the former primary', function () {
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    $ssh = fakeEngine(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $standby->server, 'replica'))->bootIdChangesAfterReboot();

    runOperation(fn () => resolve(RebootDatabase::class)->handle($database));
    $commands = engineCommandsIn($ssh);
    $rebootOf = fn (Server $server): int|false => collect($ssh->runs)->search(fn (array $run): bool => $run['server'] === $server->name && str_contains($run['script'], 'sleep 2; reboot'));

    expect(sentTo($standby->server, $ssh))->toContain('nodes.ready')->toContain('cluster.rejoin')
        ->and($rebootOf($standby->server))->toBeLessThan($rebootOf($primary->server))
        ->and(array_search("{$primary->server->name}: cluster.failover", $commands, true))->toBeGreaterThan(array_search("{$standby->server->name}: cluster.rejoin", $commands, true))
        ->and(argsOn($primary->server, 'cluster.failover'))->toBe([$standby->privateIp()])
        ->and(sentTo($primary->server, $ssh))->toContain('cluster.rejoin')
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('upgrades in the documented order and reads the engine again afterwards', function () {
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    $ssh = fakeEngine(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $standby->server, 'replica'));
    $offered = new EngineImage($database->image->repository, 'sha256:'.str_repeat('b', 64), '8.4.9', '0.2.4');
    $database->update(['available_image' => $offered, 'config_fields' => []]);
    answerRead('config.fields', [EngineField::fromPayload(new Payload(engineFieldPayload()))]);

    runOperation(fn () => resolve(UpgradeDatabase::class)->handle($database->fresh()));
    $commands = engineCommandsIn($ssh);
    $at = fn (Server $server, string $command): int|false => array_search("{$server->name}: {$command}", $commands, true);

    expect($at($standby->server, 'host deploy'))->toBeLessThan($at($primary->server, 'cluster.failover'))
        ->and($at($primary->server, 'cluster.failover'))->toBeLessThan($at($primary->server, 'host deploy'))
        ->and($at($primary->server, 'host deploy'))->toBeLessThan($at($primary->server, 'nodes.configure'))
        ->and($at($primary->server, 'nodes.configure'))->toBeLessThan($at($primary->server, 'nodes.ready'))
        ->and($at($standby->server, 'host deploy'))->toBeLessThan($at($standby->server, 'nodes.configure'))
        ->and($at($standby->server, 'nodes.configure'))->toBeLessThan($at($standby->server, 'nodes.ready'))
        ->and(json_decode($ssh->engineRequestAt(collect($ssh->runs)->search(fn (array $run): bool => $run['server'] === $primary->server->name && str_contains($run['script'], '| databasify host deploy '))), true)['image'])->toEndWith('@sha256:'.str_repeat('b', 64))
        ->and($database->fresh())->image->toEqual($offered)->available_image->toBeNull()->status->toBe(DatabaseStatus::Ready)
        ->and($database->fresh()->config_fields)->toHaveCount(1);
});

it('refuses to install an offered image built on an SDK outside the range', function () {
    $database = idleDatabase('mysql', 1);
    $ssh = fakeEngine();
    $database->update(['available_image' => new EngineImage($database->image->repository, 'sha256:'.str_repeat('b', 64), '8.4.9', '0.3.0')]);
    $image = $database->image;

    expect(fn () => resolve(UpgradeDatabase::class)->handle($database->fresh()))
        ->toThrow(ValidationException::class, 'MySQL 8.4.9 is built on SDK 0.3.0; this Databasify supports ^0.2.2 || ^1.0.')
        ->and($database->fresh())->image->toEqual($image)->status->toBe(DatabaseStatus::Ready)
        ->and($ssh->runs)->toBe([]);
});

it('refuses to reboot while a node has just stopped answering', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, $standby, $silent] = $database->nodes()->orderBy('position')->get()->all();
    answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $standby->server, 'replica'), $silent->server, null);

    expect(fn () => resolve(RebootDatabase::class)->handle($database))->toThrow(ValidationException::class, "{$silent->server->name} does not answer");
});

it('turns a single mysql node into a cluster without updating membership it does not have yet', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    markRoles($database, NodeRole::Primary);
    $existing = $database->seed()->server;
    $server = Server::factory()->ready()->create();

    addThroughFlow($database, $server);
    $commands = engineCommandsIn($ssh);

    expect(sentTo($existing, $ssh))->not->toContain('cluster.membership')
        ->and(sentTo($existing, $ssh))->toContain('nodes.configure', 'cluster.enable')
        ->and(array_search("{$existing->name}: cluster.enable", $commands, true))->toBeLessThan(array_search("{$server->name}: cluster.enable", $commands, true))
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready)
        ->and($database->nodes()->count())->toBe(2);
});

it('bootstraps a single mariadb node into galera before its first peer starts', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    markRoles($database, NodeRole::Primary);
    $existing = $database->seed()->server;
    $server = Server::factory()->ready()->create();

    addThroughFlow($database, $server);
    $commands = engineCommandsIn($ssh);

    expect(sentTo($existing, $ssh))->toBe(['nodes.configure', 'cluster.enable', 'task.status', 'nodes.configure', 'nodes.status'])
        ->and(array_search("{$existing->name}: cluster.enable", $commands, true))->toBeLessThan(array_search("{$server->name}: nodes.start", $commands, true))
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready)
        ->and($database->nodes()->count())->toBe(2);
});
