<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\CreateDatabase;
use App\Actions\Databases\Lifecycle\DeleteDatabase;
use App\Actions\Databases\Lifecycle\ReadNodeStatus;
use App\DTOs\Databases\NewDatabase;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\FlowState;
use App\Enums\Databases\NodeRole;
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
        'host deploy', 'tls install', 'engine capabilities', 'config fields', 'node configure', 'node start', 'node ready', 'operation status', 'node bootstrap', 'node status',
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
        'host deploy', 'tls install', ...($node->isSeed() ? ['engine capabilities', 'config fields'] : []), 'node configure', 'node start', 'node ready', 'operation status', 'node bootstrap', 'cluster enable', 'operation status', 'node status',
    ]));
    expect(array_search("{$database->nodes[2]->server->name}: node ready", $commands, true))->toBeLessThan(array_search("{$database->nodes[0]->server->name}: node bootstrap", $commands, true))
        ->and($database->flows()->sole()->state)->toBe(FlowState::Succeeded);
});

it('treats node bootstrap as done on an engine without accounts', function () {
    $ssh = fakeEngine(fakeSsh()->respondToEngine('node bootstrap', ['error' => ['code' => 'unsupported', 'message' => 'no accounts', 'details' => []]], exit: 1));

    $database = createThroughFlow('valkey', 1);

    expect($database->flows()->sole()->state)->toBe(FlowState::Succeeded)
        ->and(commandsOn($database->seed()->server, engineCommandsIn($ssh)))->toContain('node bootstrap');
});

it('cleans up every node when a prepare step fails', function () {
    $servers = Server::factory()->ready()->count(2)->create();
    $ssh = fakeEngine(fakeSsh()->respondWhen(fn (string $server, string $script): bool => $server === $servers[1]->name && str_contains($script, '| databasify node start '), json_encode(['error' => ['code' => 'internal', 'message' => 'the engine did not start', 'details' => (object) []]]), 1));

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

it('maps node status roles', function (array $reply, int $exit, NodeRole $role) {
    fakeSsh()->respondToEngine('node status', $reply, $exit);
    $database = idleDatabase('postgres', 1);

    expect(resolve(ReadNodeStatus::class)->handle($database, $database->seed())->role)->toBe($role);
})->with([
    'primary' => [['role' => 'primary', 'version' => '18.6'], 0, NodeRole::Primary],
    'replica' => [['role' => 'replica', 'version' => '18.6'], 0, NodeRole::Replica],
    'offline' => [['role' => 'offline', 'version' => null], 0, NodeRole::Offline],
    'container stopped' => [[], 69, NodeRole::Offline],
    'unreachable' => [['error' => ['code' => 'unavailable', 'message' => 'not yet', 'details' => []]], 1, NodeRole::Unknown],
]);
