<?php

declare(strict_types=1);

use App\Actions\Databases\Failover\RecoverCluster;
use App\Actions\Databases\Lifecycle\SetUpDatabase;
use App\Actions\Databases\Nodes\JoinNode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Models\DatabaseNode;
use App\Models\Server;

function readiedInRun(DatabaseNode $node): void
{
    startedTask("run1.{$node->id}.ready");
}

it('does not deploy, configure or start a node again on the next run of a create', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 1);
    $database->update(['status' => DatabaseStatus::Creating]);
    $seed = $database->seed();
    readiedInRun($seed);

    resolve(SetUpDatabase::class)->handle($database, 'run1');

    expect(engineCommandsIn($ssh))->not->toContain("{$seed->server->name}: host deploy")
        ->and(recordedCalls($seed->server))->not->toContain('nodes.configure')->not->toContain('nodes.start')
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('does not bootstrap the nodes again once the cluster started joining', function () {
    fakeEngine();
    $database = idleDatabase('mysql', 3);
    $database->update(['status' => DatabaseStatus::Creating]);
    $nodes = $database->orderedNodesWithout();
    $nodes->each(readiedInRun(...));
    startedTask("run1.{$nodes->first()->id}.join");

    resolve(SetUpDatabase::class)->handle($database, 'run1');

    expect(recordedCalls())->not->toContain('nodes.bootstrap');
});

it('does not redeploy the joiner while its copy runs', function () {
    $ssh = fakeEngine();
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $joiner = $database->addNode(Server::factory()->ready()->create()->id);
    $database->update(['status' => DatabaseStatus::Scaling]);
    readiedInRun($joiner);
    startedTask("run1.{$joiner->id}.copy");

    resolve(JoinNode::class)->handle($database, $joiner->load('server'), 'run1');

    expect(engineCommandsIn($ssh))->not->toContain("{$joiner->server->name}: host deploy")
        ->and(recordedCalls($joiner->server))->not->toContain('nodes.start')
        ->and($database->fresh()->status)->toBe(DatabaseStatus::Ready);
});

it('does not pick a survivor or force quorum again once the rejoins started', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 3);
    $database->update(['status' => DatabaseStatus::Recovering]);
    answerRead('cluster.position', 42);
    $nodes = $database->orderedNodesWithout();
    startedTask("run1.{$nodes[1]->id}.rejoin");
    startedTask("run1.{$nodes[2]->id}.rejoin");

    resolve(RecoverCluster::class)->handle($database, $nodes->ids(), false, 'run1');

    expect(recordedCalls())->not->toContain('cluster.forceQuorum')->not->toContain('cluster.position')->not->toContain('nodes.wake')
        ->and(collect(recordedArgs('cluster.rejoin'))->pluck('server')->all())->toBe([$nodes[1]->server->name, $nodes[2]->server->name]);
});

it('starts every rejoin before it waits on any of them', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 3);
    $database->update(['status' => DatabaseStatus::Recovering]);
    answerRead('cluster.position', 42);
    $nodes = $database->orderedNodesWithout();

    resolve(RecoverCluster::class)->handle($database, $nodes->ids(), false, 'run1');

    $calls = recordedCalls();
    $rejoins = array_keys(array_filter($calls, fn (string $call): bool => $call === 'cluster.rejoin'));
    expect($rejoins)->toHaveCount(2)
        ->and($calls[$rejoins[0] + 1])->toBe('cluster.rejoin');
});

it('refuses to finish a recovery when only some rejoins started', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 3);
    $database->update(['status' => DatabaseStatus::Recovering]);
    $nodes = $database->orderedNodesWithout();
    startedTask("run1.{$nodes[1]->id}.rejoin");

    expect(fn () => resolve(RecoverCluster::class)->handle($database, $nodes->ids(), false, 'run1'))
        ->toThrow(RuntimeException::class, 'Recovery stopped partway');

    expect(recordedCalls())->not->toContain('cluster.forceQuorum');
});
