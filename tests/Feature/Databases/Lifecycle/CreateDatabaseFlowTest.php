<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\Actions\Databases\Lifecycle\DeleteDatabase;
use App\Actions\Databases\Lifecycle\ReadNodeStatus;
use App\Actions\Databases\Recovery\RestoreToPointInTime;
use App\DTOs\Databases\NewDatabase;
use App\DTOs\Databases\NodeStatus;
use App\DTOs\Recovery\PointInTimeRestore;
use App\Engines\Data\Operation;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\FlowState;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\OperationState;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\EngineUnavailable;
use App\Exceptions\Engines\EngineUnsupported;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Server;
use Illuminate\Support\Collection;
use Tests\Support\FakeServerConnection;

function createThroughFlow(string $engine, int $servers): Database
{
    $servers = Server::factory()->ready()->count($servers)->create();

    $database = inProjectDuring(fn (): Database => resolve(CreateDatabase::class)->handle(new NewDatabase('main', $engine, $servers[0]->id, $servers->slice(1)->pluck('id')->values()->all())));
    advanceFlows();

    return $database->fresh();
}

/**
 * @param  list<string>  $commands
 * @return list<string>
 */
function commandsOn(Server $server, array $commands): array
{
    return array_values(array_map(fn (string $command): string => str($command)->after(': ')->value(), array_filter($commands, fn (string $command): bool => str_starts_with($command, "{$server->name}: "))));
}

/**
 * @return Collection<int, int>
 */
function hostRemoveRunsIn(FakeServerConnection $ssh): Collection
{
    return collect($ssh->runs)->keys()->filter(fn (int $index): bool => str_contains($ssh->scriptAt($index), '| databasify host remove '));
}

it('creates a single node through the create flow', function () {
    $ssh = fakeEngine();

    $database = createThroughFlow('postgres', 1);

    expect(commandsOn($database->seed()->server, engineCommandsIn($ssh)))->toBe([
        'host deploy', 'engine info', 'nodes.installTls', 'config.fields', 'nodes.configure', 'nodes.start', 'nodes.ready', 'task.status', 'nodes.bootstrap', 'logs read', 'nodes.status',
    ])
        ->and($database->flows()->sole()->state)->toBe(FlowState::Succeeded)
        ->and($database->operation)->toBeNull()
        ->and($database->status)->toBe(DatabaseStatus::Ready);
});

it('creates a three node cluster and enables every member', function () {
    $ssh = fakeEngine();

    $database = createThroughFlow('mysql', 3);
    $commands = engineCommandsIn($ssh);

    $database->nodes->each(fn (DatabaseNode $node) => expect(commandsOn($node->server, $commands))->toBe([
        'host deploy', 'engine info', 'nodes.installTls', ...($node->isSeed() ? ['config.fields'] : []), 'nodes.configure', 'nodes.start', 'nodes.ready', 'task.status', 'nodes.bootstrap', 'cluster.enable', 'task.status', ...($node->isSeed() ? ['logs read'] : []), 'nodes.status',
    ]));
    expect(array_search("{$database->nodes[2]->server->name}: nodes.ready", $commands, true))->toBeLessThan(array_search("{$database->nodes[0]->server->name}: nodes.bootstrap", $commands, true))
        ->and($database->flows()->sole()->state)->toBe(FlowState::Succeeded);
});

it('deploys a node without installing a separate wrapper', function () {
    $ssh = fakeEngine();

    createThroughFlow('mysql', 3);

    expect(engineCommandsIn($ssh))->each->not->toContain('host wrapper-update');
});

it('keeps a long step in one server log that closes when the operation succeeds', function () {
    fakeEngine();

    $database = createThroughFlow('postgres', 1);
    $server = $database->seed()->server;

    expect($server->serverLogs()->where('name', "{$server->name}: waiting for the node")->sole()->status)->toBe(ServerLogStatus::Succeeded)
        ->and($database->flows()->sole()->operation_log_id)->toBeNull();
});

it('fails the server log of a long step with the engine error', function () {
    fakeEngine();
    answerRead('task.status', fn (string $key): Operation => new Operation($key, 'task', OperationState::Failed, '', null, 'the node never became ready'));

    $database = createThroughFlow('postgres', 1);
    $server = $database->seed()->server;
    $log = $server->serverLogs()->where('name', "{$server->name}: waiting for the node")->sole();

    expect($log->status)->toBe(ServerLogStatus::Failed)
        ->and($log->output)->toContain('the node never became ready')
        ->and($database->flows()->sole()->state)->toBe(FlowState::Failed);
});

it('learns the log units once the nodes run', function () {
    fakeEngine(fakeSsh()->respondToEngine('logs read', ['units' => ['databasify', 'mongod'], 'lines' => []]));

    $database = createThroughFlow('mongodb', 1);

    expect($database->fresh()->log_units)->toBe(['databasify', 'mongod']);
});

it('treats node bootstrap as done on an engine without accounts', function () {
    $ssh = fakeEngine();
    answerRead('nodes.bootstrap', fn () => throw EngineUnsupported::command('node bootstrap'));

    $database = createThroughFlow('valkey', 1);

    expect($database->flows()->sole()->state)->toBe(FlowState::Succeeded)
        ->and(commandsOn($database->seed()->server, engineCommandsIn($ssh)))->toContain('nodes.bootstrap');
});

it('cleans up every node when a prepare step fails', function () {
    $servers = Server::factory()->ready()->count(2)->create();
    $ssh = fakeEngine();
    answerRead('nodes.start', fn () => throw new EngineError('internal', 'the engine did not start'), $servers[1]);

    $database = inProjectDuring(fn (): Database => resolve(CreateDatabase::class)->handle(new NewDatabase('main', 'postgres', $servers[0]->id, [$servers[1]->id])))->fresh();
    advanceFlows();
    $database->refresh();

    $removes = hostRemoveRunsIn($ssh);

    expect($database->flows()->sole())->state->toBe(FlowState::Failed)->error->toBe('the engine did not start')
        ->and($database->status)->toBe(DatabaseStatus::Failed)
        ->and($database->operation)->toBeNull()
        ->and($removes)->toHaveCount(2)
        ->and(json_decode($ssh->engineRequestAt($removes->first()), true))->toBe(['databaseId' => $database->id, 'erase' => true]);
});

it('deletes by erasing every node', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 2);
    $servers = $database->nodes->map->server;

    resolve(DeleteDatabase::class)->handle($database, eraseData: true);

    $removes = hostRemoveRunsIn($ssh);

    expect($removes->map(fn (int $index): string => $ssh->runs[$index]['server'])->sort()->values()->all())->toBe($servers->pluck('name')->sort()->values()->all())
        ->and(json_decode($ssh->engineRequestAt($removes->first()), true))->toBe(['databaseId' => $database->id, 'erase' => true])
        ->and(Database::query()->find($database->id))->toBeNull();
});

it('maps node status roles', function (Closure $reply, NodeRole $role) {
    fakeEngine();
    answerRead('nodes.status', $reply);
    $database = idleDatabase('postgres', 1);

    expect(resolve(ReadNodeStatus::class)->handle($database, $database->seed())->role)->toBe($role);
})->with([
    'primary' => [fn (): NodeStatus => NodeStatus::reported(NodeRole::Primary, '18.6'), NodeRole::Primary],
    'replica' => [fn (): NodeStatus => NodeStatus::reported(NodeRole::Replica, '18.6'), NodeRole::Replica],
    'offline' => [fn (): NodeStatus => NodeStatus::reported(NodeRole::Offline, null), NodeRole::Offline],
    'container stopped' => [fn () => throw new EngineNotRunning('not_running', 'stopped'), NodeRole::Offline],
    'unreachable' => [fn () => throw new EngineUnavailable('unavailable', 'not yet'), NodeRole::Unknown],
]);

it('restores to a point in time through the create flow before checking the accounts', function () {
    $ssh = fakeEngine();
    $source = withRecovery(idleDatabase('postgres', 1, name: 'orders'), restorable: true);
    $server = Server::factory()->ready()->create();
    $target = $source->pointInTimeRecovery->latest_restorable_at->subMinute();

    $restored = inProjectDuring(fn (): Database => resolve(RestoreToPointInTime::class)->handle($source, new PointInTimeRestore('orders-restored', $server->id, $target)));
    advanceFlows();
    $commands = commandsOn($server, engineCommandsIn($ssh));

    expect(array_search('archive.restore', $commands, true))->toBeGreaterThan(array_search('nodes.bootstrap', $commands, true))
        ->and($commands)->toContain('accounts.list')
        ->and(recordedArgs('archive.restore')[0]['args'][2]->equalTo($target))->toBeTrue()
        ->and($restored->fresh()->flows()->sole()->state)->toBe(FlowState::Succeeded);
});
