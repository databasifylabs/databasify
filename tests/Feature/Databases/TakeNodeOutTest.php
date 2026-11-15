<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\TakeNodeOut;
use App\DTOs\Databases\NodeStatus;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\NotPrimary;
use App\Models\Database;
use App\Models\DatabaseNode;
use Tests\Support\RecordingDriver;

function scalingCluster(int $members): array
{
    $database = Database::factory()->highlyAvailable()->status(DatabaseStatus::Scaling)->create();
    $nodes = collect(range(1, $members))->map(fn (int $position) => DatabaseNode::factory()->for($database)->create([
        'position' => $position,
        'role' => $position === 1 ? NodeRole::Primary : NodeRole::Replica,
    ]));

    return [$database, ...$nodes->all()];
}

test('a standby leaves and the primary carries on alone', function () {
    [$database, $primary, $standby] = scalingCluster(2);
    $engine = RecordingDriver::fake()->on('nodes.status', fn (DatabaseNode $node) => new NodeStatus($node->is($primary) ? NodeRole::Primary : NodeRole::Replica, '18.1'));
    $this->ssh()->replyTo('databasify host remove', '{}');

    app(TakeNodeOut::class)->handle($database, $standby, standby: null, force: false);

    $this->assertModelMissing($standby);
    expect($database->refresh())
        ->mode->toBe(DatabaseMode::Single)
        ->status->toBe(DatabaseStatus::Ready)
        ->and($engine->callsOf('cluster.forget')->sole()['args'])->toBe([$standby->server->private_ip])
        ->and($engine->called('cluster.disable', $primary))->toBeTrue();
});

test('the last standby is not left alone when it is not the primary', function () {
    [$database, $primary, $standby] = scalingCluster(2);
    $engine = RecordingDriver::fake()->on('nodes.status', new NodeStatus(NodeRole::Replica, '18.1'));

    expect(fn () => app(TakeNodeOut::class)->handle($database, $primary, standby: null, force: false))
        ->toThrow(NotPrimary::class, "{$standby->server->name} is not the primary, so running it on its own could serve stale data.");

    $this->assertModelExists($primary);
    expect($engine->changedNothing())->toBeTrue()
        ->and($database->refresh()->mode)->toBe(DatabaseMode::HighAvailability);
});

test('a member that cannot leave the cluster stops the removal', function () {
    [$database, , $leaving] = scalingCluster(3);
    $engine = RecordingDriver::fake()
        ->on('nodes.status', new NodeStatus(NodeRole::Replica, '18.1'))
        ->on('cluster.disable', fn (DatabaseNode $node) => $node->is($leaving) ? new EngineError('patroni did not stop') : null);

    expect(fn () => app(TakeNodeOut::class)->handle($database, $leaving, standby: null, force: false))
        ->toThrow(EngineError::class, 'patroni did not stop');

    $this->assertModelExists($leaving);
    expect($engine->called('cluster.forget'))->toBeFalse();
});

test('a forced removal goes on past a member that cannot leave the cluster', function () {
    [$database, , $leaving] = scalingCluster(3);
    RecordingDriver::fake()
        ->on('nodes.status', new NodeStatus(NodeRole::Replica, '18.1'))
        ->on('cluster.disable', fn (DatabaseNode $node) => $node->is($leaving) ? new EngineError('patroni did not stop') : null);
    $this->ssh()->replyTo('databasify host remove', '{}');

    app(TakeNodeOut::class)->handle($database, $leaving, standby: null, force: true);

    $this->assertModelMissing($leaving);
    expect($database->refresh()->nodes()->count())->toBe(2);
});
