<?php

declare(strict_types=1);

use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Servers\UpdateServerPackagesJob;
use App\Models\Server;
use App\Models\ServerLog;

it('runs the rendered script and records its output in a log', function () {
    $ssh = fakeSsh()->respond("packages are up to date\n");
    $server = Server::factory()->ready()->create();

    dispatch(new UpdateServerPackagesJob($server));

    expect($server->serverLogs()->sole())
        ->name->toBe('Update packages')
        ->status->toBe(ServerLogStatus::Succeeded)
        ->output->toContain('packages are up to date')
        ->and($ssh->scriptAt(0))->toStartWith("set -euo pipefail\n")->toContain('apt-get upgrade -yq');
});

it('fails the log when the script exits with an error', function () {
    fakeSsh()->respond("E: dpkg was interrupted\n", 100);
    $server = Server::factory()->ready()->create();

    expect(fn () => dispatch_sync(new UpdateServerPackagesJob($server)))->toThrow(RemoteScriptFailed::class);

    expect($server->serverLogs()->sole())
        ->status->toBe(ServerLogStatus::Failed)
        ->exit_code->toBe(100)
        ->output->toContain('dpkg was interrupted');
});

it('fails the running server log when its job dies', function () {
    $server = Server::factory()->ready()->create();
    $log = ServerLog::factory()->for($server)->create(['status' => ServerLogStatus::Running, 'output' => '']);

    (new UpdateServerPackagesJob($server))->failed(new RuntimeException('worker timed out'));

    expect($log->fresh())->status->toBe(ServerLogStatus::Failed)->output->toBe("▸ worker timed out\n")
        ->and($server->isBeingSetUp())->toBeFalse();
});
