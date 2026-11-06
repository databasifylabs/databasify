<?php

declare(strict_types=1);

use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Enums\Servers\ServerLogStatus;
use App\Enums\Servers\ServerStatus;
use App\Models\Server;
use App\Models\ServerLog;

it('provisions a server and forgets its password', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Provisioning, 'ssh_password' => 'secret']);

    $server->markProvisioned();

    expect($server->fresh())->status->toBe(ServerStatus::Ready)->ssh_password->toBeNull();
});

it('trusts the first host key and refuses a different one', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    expect($server->trustsHostKey('SHA256:first'))->toBeTrue()
        ->and($server->trustsHostKey('SHA256:first'))->toBeTrue()
        ->and($server->trustsHostKey('SHA256:other'))->toBeFalse();
});

it('fails a server log with its reason', function () {
    $log = ServerLog::factory()->create(['status' => ServerLogStatus::Running, 'output' => "working\n", 'exit_code' => null]);

    $log->fail('Ran out of disk.');

    expect($log->fresh())
        ->status->toBe(ServerLogStatus::Failed)
        ->output->toBe("working\n▸ Ran out of disk.\n")
        ->isRunning()->toBeFalse();
});

it('counts migration stalls and moves to syncing', function () {
    $database = idleDatabase('mysql', 1);
    $migration = $database->startMigration($database->seed(), new MigrationSource('db.example.com', 3306, 'migrator', 'secret'), MigrationMode::Continuous);

    $migration->recordStall('No answer.');
    $migration->claimSyncing();

    expect($migration->fresh())
        ->status->toBe(MigrationStatus::Syncing)
        ->unhealthy_checks->toBe(0)
        ->isSyncing()->toBeTrue()
        ->isOneTime()->toBeFalse();
});
