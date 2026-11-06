<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\EndMigration;
use App\Actions\Databases\Migrations\RefreshMigration;
use App\Actions\Databases\Migrations\RunMigration;
use App\DTOs\Migrations\MigrationPosition;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Enums\Servers\ServerLogStatus;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Ssh\RemoteScriptFailed;
use Illuminate\Support\Facades\Event;

it('copies mysql in the background and then follows the dumped binlog position', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    $ssh = fakeSsh()
        ->respond(mysqlSourceReport())
        ->respond("copying from db.example.com:3306 in the background\n")
        ->respond("copy=running\nlog=dump finished, loading into the database\n")
        ->respond("copy_exit=0\nposition=".json_encode(['file' => 'binlog.000003', 'position' => '2176'])."\nlog=done\n")
        ->respond("replicating from the source on channel migration\n");

    resolve(RunMigration::class)->handle($migration);
    expect($migration->fresh()?->status)->toBe(MigrationStatus::Copying)
        ->and($ssh->scriptAt(1))->toContain('databasify ctl run --quiet --collect --unit=databasify-mysql-migration')->toContain('"consistent":true')->not->toContain("pa'ss");

    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);
    expect($migration->fresh())->status->toBe(MigrationStatus::Copying)->detail->toContain('loading into the database');

    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);

    $migration->refresh();
    $sql = sqlSentIn($ssh->scriptAt(4));
    expect($migration->status)->toBe(MigrationStatus::Syncing)
        ->and($migration->position)->toEqual(new MigrationPosition(file: 'binlog.000003', position: '2176'))
        ->and($sql)->toContain("SOURCE_LOG_FILE = 'binlog.000003',\n    SOURCE_LOG_POS = 2176,")
        ->and($sql)->toContain("SOURCE_PASSWORD = 'pa\\'ss\\\\word'")
        ->and($sql)->not->toContain('ASSIGN_GTIDS_TO_ANONYMOUS_TRANSACTIONS')
        ->and($sql)->toEndWith("START REPLICA FOR CHANNEL 'migration';\n");
});

it('finishes one-time copies without a replication channel', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::OneTime);
    $ssh = fakeSsh()->respond(mysqlSourceReport())->respond()->respond("copy_exit=0\nposition={}\nlog=done\n")->respond("migration stopped and cleaned up\n");

    resolve(RunMigration::class)->handle($migration);
    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);

    expect($migration->fresh()?->status)->toBe(MigrationStatus::Completed)
        ->and($ssh->runs)->toHaveCount(5)
        ->and($ssh->scriptAt(4))->toContain("RESET REPLICA ALL FOR CHANNEL 'migration'");
});

it('fails and cleans up when the start or the copy fails', function () {
    $database = migratingDatabase('valkey');
    $migration = migrationOf($database, MigrationMode::Continuous);
    $ssh = fakeSsh()->respond('docker: no space left on device', 1)->respond("migration stopped\n");

    expect(fn () => resolve(RunMigration::class)->handle($migration))->toThrow(RemoteScriptFailed::class)
        ->and($migration->fresh()?->status)->toBe(MigrationStatus::Failed)
        ->and($ssh->scriptAt(1))->toContain('databasify ctl stop valkey-migration');

    $copying = migrationOf($database, MigrationMode::OneTime);
    $ssh->respond("running=false\nexit_code=1\nconsistent=false\nlog=ERR source refused\n")->respond();
    resolve(RefreshMigration::class)->handle($copying);

    expect($copying->fresh())->status->toBe(MigrationStatus::Failed)->detail->toContain('ERR source refused');
});

it('starts redis-shake in the background for sync and scan modes', function () {
    $database = migratingDatabase('valkey');
    $ssh = fakeSsh();

    foreach ([MigrationMode::Continuous, MigrationMode::OneTime] as $mode) {
        resolve(RunMigration::class)->handle(migrationOf($database, $mode));
    }

    $config = fn (int $run): string => base64_decode((string) preg_replace('/.*echo \'([A-Za-z0-9+\/=]+)\' \| base64 -d > "\$dir\/migration\/shake.toml".*/s', '$1', $ssh->scriptAt($run)));
    expect($config(0))->toContain('[sync_reader]')->toContain('password = "pa\'ss\\\\word"')
        ->toContain('address = "127.0.0.1:6379"')->toContain('status_port = 0')
        ->and($config(1))->toContain('[scan_reader]')->toContain('ksn = false')
        ->and($ssh->scriptAt(0))->toContain('--remain-after-exit')->not->toContain('docker wait')->not->toContain('wait_for 7200');
});

it('marks the copy as started once the background job is running', function () {
    $database = migratingDatabase('postgres');
    $migration = migrationOf($database, MigrationMode::OneTime);
    fakeSsh()->respond("copying from db.example.com:3306 in the background\n");

    resolve(RunMigration::class)->handle($migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Copying)->detail->toBe('Copying from the source.')
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->serverLogs()->sole())->status->toBe(ServerLogStatus::Succeeded)->output->toContain('copying from db.example.com:3306 in the background');
});

it('stops a copy that started after a stop ended the migration', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    $stop = $migration->fresh() ?? $migration;
    $runs = 0;
    $ssh = fakeSsh()->respondWhen(function () use ($stop, &$runs): bool {
        if (++$runs === 2) {
            resolve(EndMigration::class)->handle($stop, cutover: false);
        }

        return false;
    })->respond(mysqlSourceReport());

    resolve(RunMigration::class)->handle($migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Stopped)->detail->toBe('Stopped before finishing.')
        ->and($ssh->runs)->toHaveCount(4)
        ->and($ssh->scriptAt(1))->toContain('--unit=databasify-mysql-migration')
        ->and($ssh->scriptAt(3))->toContain('migration stopped and cleaned up');
});

it('stops the server side when the start fails after a stop ended the migration', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    $stop = $migration->fresh() ?? $migration;
    Event::fake([DatabaseAlertRaised::class]);
    $runs = 0;
    $ssh = fakeSsh()->respondWhen(function () use ($stop, &$runs): bool {
        if (++$runs !== 2) {
            return false;
        }

        resolve(EndMigration::class)->handle($stop, cutover: false);

        return true;
    }, 'docker: no space left on device', 1)->respond(mysqlSourceReport());

    expect(fn () => resolve(RunMigration::class)->handle($migration))->toThrow(RemoteScriptFailed::class)
        ->and($migration->fresh())->status->toBe(MigrationStatus::Stopped)->detail->toBe('Stopped before finishing.')
        ->and($ssh->runs)->toHaveCount(4)
        ->and($ssh->scriptAt(3))->toContain('migration stopped and cleaned up');
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});
