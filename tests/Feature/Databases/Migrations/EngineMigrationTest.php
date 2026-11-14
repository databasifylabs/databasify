<?php

declare(strict_types=1);

use App\Actions\Databases\Migrations\RefreshMigration;
use App\Actions\Databases\Migrations\RunMigration;
use App\Actions\Databases\Migrations\StartMigration;
use App\DTOs\Migrations\MigrationProgress;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Data\MigrationSource as EngineSource;
use App\Engines\Data\MigrationStart;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Events\DatabaseAlertRaised;
use App\Jobs\Databases\RunMigrationJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * @param  list<string>  $problems
 */
function checkReport(bool $canReplicate, array $problems = []): SourceReport
{
    return new SourceReport(true, blockers: $canReplicate ? [] : ['The source cannot replicate continuously, so only a one-time copy is possible.'], facts: ['version' => '8.4.3', 'size_mb' => '3'], problems: $problems, databases: ['shop']);
}

function sourceAt(): MigrationSource
{
    return new MigrationSource('db.example.com', 3306, 'migrator', 'secret', databases: ['shop']);
}

it('refuses a source the engine cannot reach or follow', function () {
    $database = migratingDatabase('mysql');
    fakeEngine();
    answerRead('migration.check', SourceReport::unreachable('Access denied'));

    expect(fn () => resolve(StartMigration::class)->handle($database, sourceAt(), MigrationMode::OneTime))->toThrow(ValidationException::class, 'Access denied');

    answerRead('migration.check', checkReport(false));

    expect(fn () => resolve(StartMigration::class)->handle($database->fresh(), sourceAt(), MigrationMode::Continuous))->toThrow(ValidationException::class)
        ->and($database->migrations()->count())->toBe(0);
});

it('refuses a source the engine says it cannot copy from', function () {
    $database = migratingDatabase('mysql');
    fakeEngine();
    answerRead('migration.check', checkReport(false, ['MySQL Shell copies only as a source user with SELECT on mysql.*']));

    expect(fn () => resolve(StartMigration::class)->handle($database, sourceAt(), MigrationMode::OneTime))->toThrow(ValidationException::class, 'SELECT on mysql.*')
        ->and($database->migrations()->count())->toBe(0);
});

it('starts a continuous migration on the primary and marks it copying', function () {
    fakeEngine();
    answerRead('migration.check', checkReport(true));
    $database = migratingDatabase('mysql');
    Queue::fake([RunMigrationJob::class]);

    $migration = resolve(StartMigration::class)->handle($database, sourceAt(), MigrationMode::Continuous);
    resolve(RunMigration::class)->handle($migration);

    expect(recordedArgs('migration.start')[0]['args'])->toEqual([new MigrationStart(new EngineSource('db.example.com', 3306, 'migrator', 'secret', true), ['shop'], true, 'databasify')])
        ->and($migration->fresh()->status)->toBe(MigrationStatus::Copying);
});

it('moves a continuous migration to syncing once the copy succeeded and records the lag', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);

    fakeEngine();
    answerRead('migration.progress', MigrationProgress::working('copying shop'));
    resolve(RefreshMigration::class)->handle($migration);
    expect($migration->fresh()->status)->toBe(MigrationStatus::Copying);

    answerRead('migration.progress', MigrationProgress::caughtUp('following the source', 4));
    resolve(RefreshMigration::class)->handle($migration->fresh());
    resolve(RefreshMigration::class)->handle($migration->fresh());

    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)->lag_seconds->toBe(4);
});

it('completes a one-time copy and records its databases with grants for the default user', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::OneTime);
    $migration->update(['source' => sourceAt()]);
    fakeEngine();
    answerRead('migration.progress', MigrationProgress::caughtUp('copied'));

    resolve(RefreshMigration::class)->handle($migration->fresh());

    expect($migration->fresh()->status)->toBe(MigrationStatus::Completed)
        ->and($database->schemas()->pluck('name'))->toContain('shop')
        ->and(collect(recordedArgs('accounts.grant')[0]['args'][1])->pluck('database'))->toContain('shop');
});

it('tolerates a few lost readings before failing with an alert', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    fakeEngine();
    answerRead('migration.progress', MigrationProgress::stalled('replication stopped'));

    foreach (range(1, 4) as $ignored) {
        resolve(RefreshMigration::class)->handle($migration->fresh());
    }
    expect($migration->fresh())->status->toBe(MigrationStatus::Syncing)->unhealthy_checks->toBe(4);

    foreach (range(1, 2) as $ignored) {
        resolve(RefreshMigration::class)->handle($migration->fresh());
    }

    expect($migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toContain('replication stopped');
    Event::assertDispatched(DatabaseAlertRaised::class);
});

it('fails the migration at once when the engine reports a failed copy', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    fakeEngine();
    answerRead('migration.progress', MigrationProgress::broken('mysqlsh: access denied'));

    resolve(RefreshMigration::class)->handle($migration);

    expect($migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toContain('access denied')
        ->and(recordedCalls())->toContain('migration.stop');
});
