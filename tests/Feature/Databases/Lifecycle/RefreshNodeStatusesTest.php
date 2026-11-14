<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\RefreshNodeStatuses;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Ssh\SshConnectionFailed;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Exceptions;

it('reads only the given nodes but judges health from every node', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Unknown, NodeRole::Replica);
    $primary = $database->nodes()->get()[0];
    answerNodeStatus(fakeSsh(), $database->nodes()->orderBy('position')->first()->server, 'primary');

    $nodes = resolve(RefreshNodeStatuses::class)->handle($database, $database->nodesWithServers()->filter(fn (DatabaseNode $node): bool => $node->is($primary)));

    expect(recordedCalls())->toHaveCount(1)->and($nodes)->toHaveCount(3)->and($nodes->isHealthy())->toBeFalse();
});

it('reports no serving node when every node is offline without touching the status', function () {
    $database = idleDatabase('mysql', 2);
    $database->update(['status' => DatabaseStatus::Upgrading]);
    fakeSsh()->respond('', 69);

    $nodes = resolve(RefreshNodeStatuses::class)->handle($database, $database->nodesWithServers());

    expect($nodes->anyServes())->toBeFalse()->and($database->fresh()->status)->toBe(DatabaseStatus::Upgrading);
});

it('keeps the database degraded when the primary answers and a replica does not', function () {
    $database = idleDatabase('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Unknown);
    $primary = $database->nodes()->get()[0];
    answerNodeStatus(fakeSsh(), $primary->server, 'primary');

    $nodes = resolve(RefreshNodeStatuses::class)->handle($database, $database->nodesWithServers()->filter(fn (DatabaseNode $node): bool => $node->is($primary)));

    expect($nodes->isHealthy())->toBeFalse()->and($nodes->anyServes())->toBeTrue();
});

it('records a node whose container is stopped as offline', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    fakeSsh()->respond('', 69);

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
