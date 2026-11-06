<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;

it('finds the primary, its readers and the standbys besides it', function () {
    $database = idleDatabase('postgres', 3);
    $reader = withReadOnlyNode($database, ['role' => NodeRole::Replica]);
    markRoles($database, NodeRole::Replica, NodeRole::Primary, NodeRole::Replica);

    $nodes = $database->nodesWithServers();
    $primary = $nodes->primary();

    expect($primary?->position)->toBe(1)
        ->and($nodes->soloPrimary()?->is($primary))->toBeTrue()
        ->and($nodes->members()->readers($primary)->pluck('position')->all())->toBe([0, 2])
        ->and($nodes->standbysBesides($primary)->pluck('position')->all())->toBe([0, 2])
        ->and($nodes->readOnly()->first()?->is($reader))->toBeTrue()
        ->and($nodes->members()->healthStatus())->toBe(DatabaseStatus::Ready);
});

it('reads the primary when there is no replica and reports what is wrong', function () {
    $database = idleDatabase('postgres', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Unknown, NodeRole::Primary);

    $nodes = $database->membersWithServers();
    $first = $nodes->primary();

    expect($first)->not->toBeNull()
        ->and($nodes->soloPrimary())->toBeNull()
        ->and($nodes->readers($first)->all())->toBe([$first])
        ->and($nodes->healthStatus())->toBe(DatabaseStatus::Degraded)
        ->and($nodes->problems())->toBe("Unreachable: {$nodes[1]->server->name}. 2 nodes claim to be primary.");
});

it('reports offline when no node serves', function () {
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Offline, NodeRole::Unknown);

    expect($database->nodesWithServers()->healthStatus())->toBe(DatabaseStatus::Offline);
});
