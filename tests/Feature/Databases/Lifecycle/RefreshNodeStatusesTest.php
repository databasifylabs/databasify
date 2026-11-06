<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\RefreshNodeStatuses;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\DatabaseNode;
use App\Services\Ssh\Ssh;
use Illuminate\Support\Facades\Exceptions;

it('reads only the given nodes but judges health from every node', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Unknown, NodeRole::Replica);
    $primary = $database->nodes()->get()[0];
    $ssh = fakeSsh()->respond("PRIMARY ONLINE\n");

    $status = resolve(RefreshNodeStatuses::class)->handle($database, $database->nodesWithServers()->filter(fn (DatabaseNode $node): bool => $node->is($primary)));

    expect($ssh->runs)->toHaveCount(1)->and($status)->toBe(DatabaseStatus::Degraded);
});

it('marks the database offline when every node is offline', function () {
    $database = idleDatabase('mysql', 2);
    fakeSsh()->respond('', Ssh::CONTAINER_NOT_RUNNING);

    $status = resolve(RefreshNodeStatuses::class)->handle($database, $database->nodesWithServers());

    expect($status)->toBe(DatabaseStatus::Offline)->and($database->fresh()->status)->toBe(DatabaseStatus::Offline);
});

it('keeps the database degraded when the primary answers and a replica does not', function () {
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Unknown);
    $primary = $database->nodes()->get()[0];
    fakeSsh()->respond("PRIMARY ONLINE\n");

    $status = resolve(RefreshNodeStatuses::class)->handle($database, $database->nodesWithServers()->filter(fn (DatabaseNode $node): bool => $node->is($primary)));

    expect($status)->toBe(DatabaseStatus::Degraded);
});

it('records a node whose container is stopped as offline', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    fakeSsh()->respond('', Ssh::CONTAINER_NOT_RUNNING);

    resolve(RefreshNodeStatuses::class)->handle($database, $database->nodesWithServers());

    expect($database->nodes()->first()->role)->toBe(NodeRole::Offline);
});

it('reports an unexpected error during status refresh', function () {
    Exceptions::fake();
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    fakeSsh()->failWith(new TypeError('bad'));

    resolve(RefreshNodeStatuses::class)->handle($database, $database->nodesWithServers());

    expect($database->nodes()->first()->role)->toBe(NodeRole::Unknown);
    Exceptions::assertReported(TypeError::class);
});

it('does not report an expected connection failure during status refresh', function () {
    Exceptions::fake();
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    fakeSsh()->failWith(new SshConnectionFailed('down'));

    resolve(RefreshNodeStatuses::class)->handle($database, $database->nodesWithServers());

    Exceptions::assertNothingReported();
});
