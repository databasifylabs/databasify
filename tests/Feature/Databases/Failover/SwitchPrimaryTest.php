<?php

declare(strict_types=1);

use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\FailoverDatabaseJob;

it('records the switch in its server log and frees the database', function () {
    $database = idleDatabase('valkey', 2);
    $database->startOperation('Fail over');
    fakeSsh()->respond("role:master\n")->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond('primary moved')->respond("role:slave\nmaster_link_status:up\nmaster_sync_in_progress:0\n")->respond("role:master\n");

    runJob(new FailoverDatabaseJob($database));

    expect($database->serverLogs()->sole())->status->toBe(ServerLogStatus::Succeeded)->output->toContain('primary moved')
        ->and($database->fresh()->operation)->toBeNull();
});

it('frees the database when there is nothing to switch to', function () {
    $database = idleDatabase('valkey', 2);
    $database->startOperation('Fail over');
    fakeSsh()->respond("role:master\n")->respond('', 255);

    expect(fn () => runJob(new FailoverDatabaseJob($database)))->toThrow(RuntimeException::class, 'No healthy primary and standby to switch between.')
        ->and($database->fresh()->operation)->toBeNull();
});
