<?php

declare(strict_types=1);

use App\Actions\Databases\Failover\PromoteStandby;
use App\Enums\Databases\NodeRole;

it('asks sentinel on the primary to hand over to a standby', function () {
    $database = idleDatabase('valkey', 2);
    [$primary, $standby] = $database->nodes()->get()->all();
    $ssh = fakeSsh()->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond()->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:master\n");

    resolve(PromoteStandby::class)->handle($database);

    expect($ssh->runs)->toHaveCount(5)
        ->and($ssh->runs[2]['server'])->toBe($primary->server->name)
        ->and($ssh->scriptAt(2))->toContain('SENTINEL FAILOVER main')
        ->and($primary->fresh()->role)->toBe(NodeRole::Replica)
        ->and($standby->fresh()->role)->toBe(NodeRole::Primary)
        ->and($database->serverLogs()->pluck('name')->all())->toBe(["moving the primary off {$primary->server->name}"]);
});

it('refuses to switch when no healthy standby answers', function () {
    $database = idleDatabase('valkey', 2);
    withReadOnlyNode($database);
    $ssh = fakeSsh()->respond("role:master\n")->respond('', 255)->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n");

    expect(fn () => resolve(PromoteStandby::class)->handle($database))
        ->toThrow(RuntimeException::class, 'No healthy primary and standby to switch between.')
        ->and($ssh->runs)->toHaveCount(3);
});
