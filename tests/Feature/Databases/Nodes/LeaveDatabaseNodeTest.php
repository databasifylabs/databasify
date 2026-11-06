<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\LeaveDatabaseNode;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\RemoveDatabaseNodeJob;

it('erases the data of a node that leaves the database', function () {
    $database = idleDatabase('valkey', 2);
    $leaving = $database->nodes()->get()[1];
    $ssh = fakeSsh();

    resolve(LeaveDatabaseNode::class)->handle($leaving);

    expect($ssh->scriptAt(2))->toContain('rm -rf "$dir"')
        ->and($database->nodes()->count())->toBe(1);
});

it('turns the database single once only one member is left', function () {
    $database = idleDatabase('valkey', 2);
    $database->startOperation('Remove');
    fakeSsh()->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");

    runJob(new RemoveDatabaseNodeJob($database->nodes()->get()[1]));

    expect($database->fresh()->mode)->toBe(DatabaseMode::Single)
        ->and($database->fresh()->operation)->toBeNull();
});

it('refuses to remove a node that became the primary since the request', function () {
    $database = idleDatabase('valkey', 2);
    $leaving = $database->nodes()->get()[1];
    $database->startOperation('Remove');
    $ssh = fakeSsh()->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:master\n");

    expect(fn () => runJob(new RemoveDatabaseNodeJob($leaving)))->toThrow(RuntimeException::class, 'became the primary since the request')
        ->and($ssh->runs)->toHaveCount(2)
        ->and($database->nodes()->count())->toBe(2)
        ->and($database->fresh()->operation)->toBeNull();
});

it('force removes a node while the primary is unreachable and fails naming what was left', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Replica);
    [$primary, , $leaving] = $database->nodes()->get()->all();
    fakeSsh()->respondWhen(fn (string $on): bool => $on === $primary->server->name, 'ssh: connect timed out', 255);

    expect(fn () => resolve(LeaveDatabaseNode::class)->handle($leaving, force: true))
        ->toThrow(RuntimeException::class, "{$leaving->server->name} was removed, but {$primary->server->name}: removing {$leaving->server->name} from the cluster on {$primary->server->name} failed")
        ->and($database->nodes()->count())->toBe(2);
});
