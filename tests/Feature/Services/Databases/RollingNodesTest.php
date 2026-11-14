<?php

declare(strict_types=1);

use App\Actions\Databases\Configuration\RollOutSettings;
use App\Actions\Databases\Failover\RejoinMembers;
use App\DTOs\Databases\NodeStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;

function rollingDatabase(DatabaseStatus $status): Database
{
    $database = idleDatabase('postgres', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    $database->update(['status' => $status]);

    return $database;
}

function finishedRunOn(DatabaseNode $node): void
{
    startedTask("run1.{$node->id}.ready");
    startedTask("run1.{$node->id}.rejoin");
}

it('does not restart or fail over again a node that already started this run', function () {
    fakeEngine();
    $database = rollingDatabase(DatabaseStatus::Rebooting);
    $primary = $database->nodesWithServers()->primary();
    finishedRunOn($primary);

    resolve(RollingNodes::class)->restart($database, 'run1');

    expect(recordedCalls($primary->server))->not->toContain('nodes.restart')->not->toContain('cluster.failover')
        ->and(collect(recordedCalls())->filter(fn (string $call): bool => $call === 'nodes.restart'))->toHaveCount(2);
});

it('restarts no node again when the roles changed after a failover in this run', function () {
    fakeEngine();
    $database = rollingDatabase(DatabaseStatus::Rebooting);
    $database->nodesWithServers()->each(finishedRunOn(...));
    markRoles($database, NodeRole::Replica, NodeRole::Primary, NodeRole::Replica);

    resolve(RollingNodes::class)->restart($database, 'run1');

    expect(recordedCalls())->not->toContain('nodes.restart')->not->toContain('cluster.failover');
});

it('keeps rolling the restart on the next run after the settings were applied', function () {
    fakeEngine();
    $database = rollingDatabase(DatabaseStatus::Updating);
    $nodes = $database->nodesWithServers();
    finishedRunOn($nodes->standbysBesides($nodes->primary())->first());

    resolve(RollOutSettings::class)->handle($database, false, 'run1');

    expect(collect(recordedCalls())->filter(fn (string $call): bool => $call === 'nodes.restart'))->toHaveCount(2);
});

it('does not redeploy a member that already started rejoining in this run', function () {
    $ssh = fakeEngine();
    $database = rollingDatabase(DatabaseStatus::Recovering);
    $member = $database->nodesWithServers()->last();
    finishedRunOn($member);

    resolve(RejoinMembers::class)->handle($database, [$member->id], 'run1');

    expect(engineCommandsIn($ssh))->not->toContain("{$member->server->name}: host deploy");
});

it('does not fail over again when the primary already handed over before a crash', function () {
    fakeEngine();
    $database = rollingDatabase(DatabaseStatus::Rebooting);
    $primary = $database->nodesWithServers()->primary();
    answerRead('nodes.status', NodeStatus::reported(NodeRole::Replica, '1.0'), $primary->server);

    resolve(RollingNodes::class)->restart($database, 'run1');

    expect(recordedCalls())->not->toContain('cluster.failover');
});
