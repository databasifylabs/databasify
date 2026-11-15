<?php

declare(strict_types=1);

use App\Actions\Databases\Failover\DetectQuorum;
use App\Actions\Databases\Failover\RecoverCluster;
use App\Actions\Databases\Failover\SwitchPrimary;
use App\DTOs\Databases\NodeStatus;
use App\DTOs\Databases\QuorumCount;
use App\Engines\Data\Operation;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\OperationState;
use App\Exceptions\Engines\EngineError;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\Support\RecordingDriver;

/**
 * @return Collection<int, DatabaseNode>
 */
function membersOf(Database $database, int $count): Collection
{
    return collect(range(1, $count))->map(fn (int $position) => DatabaseNode::factory()->for($database)->create([
        'position' => $position,
        'role' => $position === 1 ? NodeRole::Primary : NodeRole::Replica,
    ]));
}

test('recovery promotes the member whose data got furthest and rejoins the rest', function () {
    $database = Database::factory()->highlyAvailable()->status(DatabaseStatus::Recovering)->create();
    [$behind, $ahead] = membersOf($database, 3);
    $engine = RecordingDriver::fake()
        ->on('cluster.position', fn (DatabaseNode $node) => $node->is($ahead) ? 42 : 10)
        ->on('cluster.rejoin', fn (DatabaseNode $node, string $key) => new Operation($key, 'rejoin', OperationState::Running, 'starting', null, null))
        ->on('nodes.status', new NodeStatus(NodeRole::Replica, '18.1'));
    $this->ssh()
        ->replyInTurn('databasify task status', [
            ['{"error":{"code":"not_found","message":"no such task"}}', 1],
            ['{"error":{"code":"not_found","message":"no such task"}}', 1],
            ['{"key":"rejoin","kind":"rejoin","state":"succeeded","phase":"done"}', 0],
        ])
        ->replyTo('databasify task log', '{"lines":""}');

    app(RecoverCluster::class)->handle($database, [$behind->id, $ahead->id], othersDown: true, run: 'run1');

    expect($engine->callsOf('cluster.forceQuorum')->sole())
        ->node->is($ahead)->toBeTrue()
        ->args->toBe([true])
        ->and($engine->callsOf('cluster.rejoin')->sole()['node']->is($behind))->toBeTrue()
        ->and($database->refresh()->status)->toBe(DatabaseStatus::Ready);
});

test('recovery does not pick a primary when a member cannot say how far its data got', function () {
    $database = Database::factory()->highlyAvailable()->status(DatabaseStatus::Recovering)->create();
    [$first, $second] = membersOf($database, 3);
    $engine = RecordingDriver::fake()->on('cluster.position', fn (DatabaseNode $node) => $node->is($first) ? 10 : null);
    $this->ssh()->replyTo('databasify task status', '{"error":{"code":"not_found","message":"no such task"}}', exit: 1);

    expect(fn () => app(RecoverCluster::class)->handle($database, [$first->id, $second->id], othersDown: true, run: 'run1'))
        ->toThrow(RuntimeException::class, 'A member could not say how far its data got, so Databasify will not pick a new primary. Check the members and recover again.');

    expect($engine->called('cluster.forceQuorum'))->toBeFalse()
        ->and($engine->called('cluster.rejoin'))->toBeFalse();
});

test('quorum holds when any reachable member reports a majority', function () {
    $database = Database::factory()->highlyAvailable()->create();
    membersOf($database, 3);
    RecordingDriver::fake()->on('cluster.quorum', fn (DatabaseNode $node) => new QuorumCount(3, $node->position === 3 ? 2 : 1));

    expect(app(DetectQuorum::class)->handle($database))->toBeTrue();
});

test('quorum detection refuses to guess when no member answers', function () {
    $database = Database::factory()->highlyAvailable()->create(['name' => 'orders']);
    membersOf($database, 2);
    RecordingDriver::fake()->on('cluster.quorum', new EngineError('timeout'));

    expect(fn () => app(DetectQuorum::class)->handle($database))
        ->toThrow(ValidationException::class, 'Databasify could not read the quorum of orders, try again in a moment.');
});

test('a switchover fails over from the primary to an answering standby', function () {
    $database = Database::factory()->highlyAvailable()->status(DatabaseStatus::Recovering)->create();
    [$primary, $standby] = membersOf($database, 2);
    $engine = RecordingDriver::fake()->on('nodes.status', fn (DatabaseNode $node) => new NodeStatus($node->is($primary) ? NodeRole::Primary : NodeRole::Replica, '18.1'));

    app(SwitchPrimary::class)->handle($database);

    expect($engine->callsOf('cluster.failover')->sole())
        ->node->is($primary)->toBeTrue()
        ->args->toBe([$standby->server->private_ip])
        ->and($database->refresh()->status)->toBe(DatabaseStatus::Ready);
});

test('a switchover needs a healthy primary and standby', function () {
    $database = Database::factory()->highlyAvailable()->status(DatabaseStatus::Recovering)->create();
    membersOf($database, 2);
    $engine = RecordingDriver::fake()->on('nodes.status', new NodeStatus(NodeRole::Replica, '18.1'));

    expect(fn () => app(SwitchPrimary::class)->handle($database))->toThrow(RuntimeException::class, 'No healthy primary and standby to switch between.');

    expect($engine->called('cluster.failover'))->toBeFalse()
        ->and($database->refresh()->status)->toBe(DatabaseStatus::Recovering);
});
