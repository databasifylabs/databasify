<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\StartMigration;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Databases\NodeRole;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\RunMigrationJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('refuses continuous migrations the source cannot support', function () {
    $database = migratingDatabase('mysql');
    Queue::fake([RunMigrationJob::class]);
    fakeSsh()->respond(mysqlSourceReport(logBin: '0'))->respond(mysqlSourceReport(logBin: '0'));
    $source = new MigrationSource('db.example.com', 3306, 'migrator', 'secret');

    expect(fn () => resolve(StartMigration::class)->handle($database, $source, MigrationMode::Continuous))
        ->toThrow(ValidationException::class, 'Continuous sync is not possible');

    $migration = resolve(StartMigration::class)->handle($database, $source, MigrationMode::OneTime);

    expect($migration->status)->toBe(MigrationStatus::Copying);
    Queue::assertPushed(RunMigrationJob::class, 1);
});

it('migrates into the healthy primary only', function () {
    $database = migratingDatabase('valkey');
    $database->seed()->update(['role' => NodeRole::Unknown]);
    Queue::fake([RunMigrationJob::class]);
    $ssh = fakeSsh();

    expect(fn () => resolve(StartMigration::class)->handle($database, new MigrationSource('cache.example.com', 6379, '', ''), MigrationMode::OneTime))
        ->toThrow(ValidationException::class, 'has no healthy primary to migrate into')
        ->and($ssh->runs)->toBe([]);
    Queue::assertNothingPushed();
});

it('refuses to start while the database is busy or already migrating', function () {
    $busy = migratingDatabase('postgres');
    $busy->startOperation('Upgrade');
    $migrating = migratingDatabase('mariadb');
    migrationOf($migrating, MigrationMode::Continuous);
    Queue::fake([RunMigrationJob::class]);
    $source = new MigrationSource('db.example.com', 3306, 'migrator', 'secret');

    expect(fn () => resolve(StartMigration::class)->handle($busy, $source, MigrationMode::OneTime))->toThrow(ValidationException::class, 'is busy with Upgrade')
        ->and(fn () => resolve(StartMigration::class)->handle($migrating, $source, MigrationMode::OneTime))->toThrow(ValidationException::class, 'is migrating data in');
    Queue::assertNothingPushed();
});

it('refuses sources it cannot reach', function () {
    $database = migratingDatabase('mariadb');
    Queue::fake([RunMigrationJob::class]);
    fakeSsh()->respond("reachable=no\nerror=Unknown MySQL server host\n");

    expect(fn () => resolve(StartMigration::class)->handle($database, new MigrationSource('db.example.com', 3306, 'migrator', 'secret'), MigrationMode::OneTime))
        ->toThrow(ValidationException::class, 'Could not connect to the source: Unknown MySQL server host')
        ->and($database->migrations()->count())->toBe(0);
});

it('records the migration, marks the database busy and queues the copy', function () {
    $database = migratingDatabase('postgres');
    Queue::fake([RunMigrationJob::class]);
    fakeSsh()->respond("reachable=yes\nversion=17.2\nwal_level=logical\ncan_replicate=t\n");
    $source = new MigrationSource('pg.example.com', 5432, 'migrator', 'secret', true, ['shop']);

    $migration = resolve(StartMigration::class)->handle($database, $source, MigrationMode::Continuous);

    expect($migration->fresh())->mode->toBe(MigrationMode::Continuous)->database_node_id->toBe($database->seed()->id)->started_at->not->toBeNull()
        ->and($migration->fresh()?->source)->toEqual($source)
        ->and($database->fresh()->operation)->toBe('Migrate from pg.example.com:5432');
    Queue::assertPushed(RunMigrationJob::class, fn (RunMigrationJob $job): bool => $job->migration->is($migration));
});
