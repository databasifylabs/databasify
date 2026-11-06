<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\EndMigration;
use App\Actions\Databases\Migrations\RefreshMigration;
use App\Enums\Databases\NodeRole;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Events\DatabaseAlertRaised;
use App\Jobs\Databases\RefreshMigrationJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('moves a valkey sync to syncing once redis-shake is consistent', function () {
    $database = migratingDatabase('valkey');
    $migration = migrationOf($database, MigrationMode::Continuous);
    fakeSsh()
        ->respond("running=true\nexit_code=0\nconsistent=false\nlog=syncing rdb\n")
        ->respond("running=true\nexit_code=0\nconsistent=true\nlog=syncing aof\n");

    resolve(RefreshMigration::class)->handle($migration);
    expect($migration->fresh()?->status)->toBe(MigrationStatus::Copying);

    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);
    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)->unhealthy_checks->toBe(0);
});

it('fails a migration whose primary moved to another node', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    $database->seed()->update(['role' => NodeRole::Replica]);
    withReadOnlyNode($database, ['role' => NodeRole::Primary]);
    fakeSsh();

    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toContain('primary moved away');
});

it('keeps a migration going when its host misses one primary reading and no other node took over', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    $database->seed()->update(['role' => NodeRole::Unknown]);
    $ssh = fakeSsh();

    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)->unhealthy_checks->toBe(1)->detail->toContain('did not report as the primary')
        ->and($ssh->runs)->toBe([]);
});

it('records lag and tolerates a few stalls before failing with an alert', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    Event::fake([DatabaseAlertRaised::class]);
    $ssh = fakeSsh()->respond("Replica_IO_Running=Yes\nReplica_SQL_Running=Yes\nSeconds_Behind_Source=4\nLast_IO_Error=\nLast_SQL_Error=\n");
    foreach (range(1, 5) as $ignored) {
        $ssh->respond("Replica_IO_Running=Connecting\nReplica_SQL_Running=Yes\nSeconds_Behind_Source=NULL\nLast_IO_Error=Access denied for user 'migrator'\nLast_SQL_Error=\n");
    }

    resolve(RefreshMigration::class)->handle($migration);
    expect($migration->fresh())->lag_seconds->toBe(4)->detail->toBe('Replicating, 4s behind the source.');

    foreach (range(1, 4) as $ignored) {
        resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);
    }
    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)->unhealthy_checks->toBe(4);
    Event::assertNotDispatched(DatabaseAlertRaised::class);

    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);
    expect($migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toBe("Access denied for user 'migrator'")
        ->and(collect($ssh->runs)->last()['script'] ?? '')->toContain("RESET REPLICA ALL FOR CHANNEL 'migration'");
    Event::assertDispatched(DatabaseAlertRaised::class);
});

it('leaves ended migrations alone', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Stopped);
    $ssh = fakeSsh();

    resolve(RefreshMigration::class)->handle($migration);

    expect($ssh->runs)->toBe([])
        ->and($migration->fresh()?->status)->toBe(MigrationStatus::Stopped);
});

it('counts an unreachable server as a stall and resets the count once working again', function () {
    $database = migratingDatabase('postgres');
    $migration = migrationOf($database, MigrationMode::Continuous);
    fakeSsh()->respond('ssh: connect to host timed out', 255)->respond("copy=running\nlog=copying shop\n");

    resolve(RefreshMigration::class)->handle($migration);
    expect($migration->fresh())->status->toBe(MigrationStatus::Copying)->unhealthy_checks->toBe(1)->detail->toStartWith('Could not check the migration: ');

    resolve(RefreshMigration::class)->handle($migration->fresh() ?? $migration);
    expect($migration->fresh())->unhealthy_checks->toBe(0)->detail->toBe('Copying: copying shop');
});

it('fails the migration with an alert when following the source fails', function () {
    $database = migratingDatabase('mariadb');
    $migration = migrationOf($database, MigrationMode::Continuous);
    Event::fake([DatabaseAlertRaised::class]);
    $ssh = fakeSsh()->respond("copy_exit=0\nposition=\nlog=copied every database\n")->respond("migration stopped and cleaned up\n");

    resolve(RefreshMigration::class)->handle($migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toBe('The dump has no binlog position, so the source cannot be replicated.')->finished_at->not->toBeNull()
        ->and($ssh->scriptAt(1))->toContain("RESET SLAVE 'migration' ALL");
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->subject === "Migration into {$database->name} failed");
});

it('completes one-time copies even when the cleanup fails', function () {
    $database = migratingDatabase('redis');
    $migration = migrationOf($database, MigrationMode::OneTime);
    fakeSsh()->respond("running=false\nexit_code=0\nconsistent=false\nlog=done\n")->respond('docker: daemon gone', 1);

    resolve(RefreshMigration::class)->handle($migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Completed)->detail->toBe('Copied every key from the source.');
});

it('queues a progress check for every active migration', function () {
    $database = migratingDatabase('mysql');
    $active = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    migrationOf($database, MigrationMode::OneTime, MigrationStatus::Completed);
    Queue::fake([RefreshMigrationJob::class]);

    $this->artisan('databases:refresh-migrations')->expectsOutputToContain('Queued 1 migration checks.')->assertSuccessful();

    Queue::assertPushed(RefreshMigrationJob::class, 1);
    Queue::assertPushed(RefreshMigrationJob::class, fn (RefreshMigrationJob $job): bool => $job->migration->is($active));
});

function mysqlCopyFinished(): string
{
    return "copy_exit=0\nposition={\"file\":\"binlog.000003\",\"position\":\"157\"}\nlog=copied\n";
}

it('never starts replication for a continuous migration a stop already ended', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    $stale = $migration->fresh() ?? $migration;
    $ssh = fakeSsh()->respond()->respond(mysqlCopyFinished());

    resolve(EndMigration::class)->handle($migration, cutover: false);
    resolve(RefreshMigration::class)->handle($stale);

    expect($migration->fresh())->status->toBe(MigrationStatus::Stopped)->detail->toBe('Stopped before finishing.')
        ->and($ssh->runs)->toHaveCount(2)
        ->and(collect($ssh->runs)->filter(fn (array $run): bool => str_contains(sqlSentIn($run['script']), 'START REPLICA')))->toBeEmpty();
});

it('starts replication once when two refreshes see the same finished copy', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    $stale = $migration->fresh() ?? $migration;
    $ssh = fakeSsh()->respond(mysqlCopyFinished())->respond()->respond(mysqlCopyFinished());

    resolve(RefreshMigration::class)->handle($migration);
    resolve(RefreshMigration::class)->handle($stale);

    expect($migration->fresh()?->status)->toBe(MigrationStatus::Syncing)
        ->and(collect($ssh->runs)->filter(fn (array $run): bool => str_contains(sqlSentIn($run['script']), 'START REPLICA')))->toHaveCount(1);
});

it('stops replication that the follow step created after a stop won', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    $stop = $migration->fresh() ?? $migration;
    $ssh = fakeSsh()->respond(mysqlCopyFinished())->respondWhen(function (string $server, string $script) use ($stop): bool {
        if (str_contains(sqlSentIn($script), 'START REPLICA')) {
            resolve(EndMigration::class)->handle($stop, cutover: false);
        }

        return false;
    });

    resolve(RefreshMigration::class)->handle($migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Stopped)->detail->toBe('Stopped before finishing.')
        ->and($ssh->runs)->toHaveCount(4)
        ->and(sqlSentIn($ssh->scriptAt(1)))->toContain('START REPLICA')
        ->and($ssh->scriptAt(2))->toContain('migration stopped and cleaned up')
        ->and($ssh->scriptAt(3))->toContain('migration stopped and cleaned up');
});

it('stops replication when the follow step fails after a stop won', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    $stop = $migration->fresh() ?? $migration;
    Event::fake([DatabaseAlertRaised::class]);
    $ssh = fakeSsh()->respond(mysqlCopyFinished())->respondWhen(function (string $server, string $script) use ($stop): bool {
        if (! str_contains(sqlSentIn($script), 'START REPLICA')) {
            return false;
        }

        resolve(EndMigration::class)->handle($stop, cutover: false);

        return true;
    }, 'replica did not start in 120s', 1);

    resolve(RefreshMigration::class)->handle($migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Stopped)->detail->toBe('Stopped before finishing.')
        ->and($ssh->runs)->toHaveCount(4)
        ->and(collect($ssh->runs)->last()['script'] ?? '')->toContain('migration stopped and cleaned up');
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

it('never overwrites how an ended migration finished with a late progress reading', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    $stale = $migration->fresh() ?? $migration;
    fakeSsh()->respond()->respond("Replica_IO_Running=Yes\nReplica_SQL_Running=Yes\nSeconds_Behind_Source=4\nLast_IO_Error=\nLast_SQL_Error=\n");

    resolve(EndMigration::class)->handle($migration, cutover: false);
    resolve(RefreshMigration::class)->handle($stale);

    expect($migration->fresh())->status->toBe(MigrationStatus::Stopped)->detail->toBe('Stopped before finishing.')->lag_seconds->toBeNull();
});
