<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\FailMigration;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Events\DatabaseAlertRaised;
use Illuminate\Support\Facades\Event;

it('cleans up the server, records the reason and raises an alert', function () {
    $database = migratingDatabase('valkey');
    $migration = migrationOf($database, MigrationMode::Continuous);
    Event::fake([DatabaseAlertRaised::class]);
    $ssh = fakeSsh();

    resolve(FailMigration::class)->handle($migration, 'redis-shake stopped');

    expect($migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toBe('redis-shake stopped')->finished_at->not->toBeNull()
        ->and($ssh->scriptAt(0))->toContain('databasify ctl stop valkey-migration');
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->database->is($database) && $alert->subject === "Migration into {$database->name} failed" && $alert->message === 'redis-shake stopped');
});

it('still fails the migration when the cleanup cannot reach the server', function () {
    $database = migratingDatabase('postgres');
    $migration = migrationOf($database, MigrationMode::OneTime);
    Event::fake([DatabaseAlertRaised::class]);
    fakeSsh()->respond('ssh: connect to host timed out', 255);

    resolve(FailMigration::class)->handle($migration, 'The copy failed: pg_restore: error');

    expect($migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toBe('The copy failed: pg_restore: error');
    Event::assertDispatched(DatabaseAlertRaised::class);
});

it('never fails a migration that another check already completed', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::OneTime);
    $stale = $migration->fresh() ?? $migration;
    Event::fake([DatabaseAlertRaised::class]);
    $ssh = fakeSsh();
    $migration->finishIfActive(MigrationStatus::Completed, 'Copied every table from the source.');

    resolve(FailMigration::class)->handle($stale, 'The copy failed: mysqldump: error');

    expect($migration->fresh())->status->toBe(MigrationStatus::Completed)->detail->toBe('Copied every table from the source.')
        ->and($ssh->runs)->toBe([]);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});
