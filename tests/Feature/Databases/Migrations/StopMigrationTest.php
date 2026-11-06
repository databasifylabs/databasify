<?php

declare(strict_types=1);

use App\Actions\Databases\Failover\FailoverDatabase;
use App\Actions\Databases\Migrations\EndMigration;
use App\Actions\Databases\Migrations\StopMigration;
use App\DatabaseProviders\Groups\EngineMigration;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Jobs\Databases\RefreshMigrationJob;
use App\Jobs\Databases\RunMigrationJob;
use App\Jobs\Databases\StopMigrationJob;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('blocks other operations while migrating and finishes only while syncing', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous);
    Queue::fake([StopMigrationJob::class]);

    expect(fn () => resolve(FailoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'migrating data in')
        ->and(fn () => resolve(StopMigration::class)->handle($migration, cutover: true))->toThrow(ValidationException::class, 'still running');

    resolve(StopMigration::class)->handle($migration, cutover: false, force: true);
    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job): bool => ! $job->cutover && $job->force);
});

it('completes, stops or force stops a migration when it ends', function () {
    $database = migratingDatabase('mysql');
    $finished = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    $cancelled = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    $unreachable = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    $ssh = fakeSsh()->respond()->respond()->respond()->respond('ssh: connect to host timed out', 255)->respond('ssh: connect to host timed out', 255);

    resolve(EndMigration::class)->handle($finished, cutover: true);
    resolve(EndMigration::class)->handle($cancelled, cutover: false);

    expect(fn () => resolve(EndMigration::class)->handle($unreachable, cutover: false))->toThrow(RemoteScriptFailed::class)
        ->and($unreachable->fresh()?->status)->toBe(MigrationStatus::Stopped);

    resolve(EndMigration::class)->handle($unreachable->fresh() ?? $unreachable, cutover: false, force: true);

    expect($finished->fresh()?->status)->toBe(MigrationStatus::Completed)
        ->and($cancelled->fresh()?->status)->toBe(MigrationStatus::Stopped)
        ->and($unreachable->fresh())->status->toBe(MigrationStatus::Stopped)->detail->toBe('Stopped before finishing.')
        ->and($ssh->scriptAt(1))->toContain("RESET REPLICA ALL FOR CHANNEL 'migration'")
        ->and($database->serverLogs()->where('name', 'Clean up migration')->where('status', ServerLogStatus::Failed)->pluck('output')->all())
        ->toHaveCount(2)->each->toContain('replication from the source may still be running. Use Clean up again on the Migrations tab.');
});

it('lets a force stop clean up a migration that already ended', function () {
    $database = migratingDatabase('mysql');
    $ended = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Stopped);
    $ended->update(['cleanup_failed_at' => now()]);
    Queue::fake([StopMigrationJob::class]);

    expect(fn () => resolve(StopMigration::class)->handle($ended, cutover: false))->toThrow(ValidationException::class, 'already ended');

    resolve(StopMigration::class)->handle($ended, cutover: false, force: true);
    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job): bool => $job->force);

    $ssh = fakeSsh()->respond('ssh: connect to host timed out', 255);
    resolve(EndMigration::class)->handle($ended, cutover: false, force: true);
    expect($ended->fresh())->status->toBe(MigrationStatus::Stopped)->cleanupFailed()->toBeTrue();

    resolve(EndMigration::class)->handle($ended, cutover: false, force: true);

    expect($ended->fresh())->status->toBe(MigrationStatus::Stopped)->cleanupFailed()->toBeFalse()
        ->and($ssh->scriptAt(1))->toContain('migration stopped and cleaned up');
});

it('refuses to stop a migration that already ended and marks the database busy stopping it', function () {
    $database = migratingDatabase('valkey');
    $ended = migrationOf($database, MigrationMode::OneTime, MigrationStatus::Failed);
    $active = migrationOf($database, MigrationMode::OneTime);
    Queue::fake([StopMigrationJob::class]);

    expect(fn () => resolve(StopMigration::class)->handle($ended, cutover: false))->toThrow(ValidationException::class, 'already ended');

    resolve(StopMigration::class)->handle($active, cutover: false);

    expect($database->fresh()->operation)->toBe('Stop migration');
    Queue::assertPushed(StopMigrationJob::class, 1);
});

it('explains how each migration ended', function () {
    $database = migratingDatabase('postgres');
    $finished = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    $stopped = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    fakeSsh();

    resolve(EndMigration::class)->handle($finished, cutover: true);
    resolve(EndMigration::class)->handle($stopped, cutover: false);

    expect($finished->fresh())->detail->toBe('Finished: the database no longer follows the source, point your app at it.')->finished_at->not->toBeNull()
        ->and($stopped->fresh())->detail->toBe('Stopped before finishing.')->finished_at->not->toBeNull();
});

it('refuses to force clean up an ended migration whose cleanup succeeded', function () {
    $database = migratingDatabase('mysql');
    $ended = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Stopped);
    Queue::fake([StopMigrationJob::class]);

    expect(fn () => resolve(StopMigration::class)->handle($ended, cutover: false, force: true))->toThrow(ValidationException::class, 'This migration has already ended.');

    Queue::assertNothingPushed();
    expect($database->fresh()->operation)->toBeNull();
});

it('refuses to clean up an ended migration while another migration runs on the database', function () {
    $database = migratingDatabase('mysql');
    $ended = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Stopped);
    $ended->update(['cleanup_failed_at' => now()]);
    migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    Queue::fake([StopMigrationJob::class]);

    expect(fn () => resolve(StopMigration::class)->handle($ended, cutover: false, force: true))->toThrow(ValidationException::class, "Another migration is running on {$database->name}, stop it first.");

    Queue::assertNothingPushed();
    expect($database->fresh()->operation)->toBeNull();
});

it('marks the cleanup unfinished before the engine stop runs and clears it once the stop succeeds', function (bool $cutover) {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    $migration->update(['source' => new MigrationSource('db.example.com', 3306, 'migrator', 'secret', databases: ['shop'])]);
    $duringStop = null;
    fakeSsh()->respondWhen(function (string $server, string $script) use ($migration, &$duringStop): bool {
        if (str_contains($script, "RESET REPLICA ALL FOR CHANNEL 'migration'")) {
            $duringStop = ['cleanupFailed' => $migration->fresh()?->cleanupFailed(), 'schemas' => $migration->database->schemaNames()];
        }

        return false;
    });

    resolve(EndMigration::class)->handle($migration->fresh() ?? $migration, $cutover);

    expect($duringStop)->toBe(['cleanupFailed' => true, 'schemas' => $cutover ? ['defaultdb', 'shop'] : ['defaultdb']])
        ->and($migration->fresh()?->cleanupFailed())->toBeFalse();
})->with(['cut over' => true, 'stopped' => false]);

it('caps the engine stop below the timeout of every job that runs it', function (string $engine) {
    $database = migratingDatabase($engine);
    $ssh = fakeSsh();

    $database->provider()->migration()?->stop(migrationOf($database, MigrationMode::Continuous));

    $jobTimeouts = collect([StopMigrationJob::class, RefreshMigrationJob::class, RunMigrationJob::class])
        ->map(fn (string $job): int => (new ReflectionClass($job))->getAttributes(Timeout::class)[0]->newInstance()->timeout);

    expect($ssh->runs[0]['timeout'])->toBe(EngineMigration::STOP_TIMEOUT)
        ->and(EngineMigration::STOP_TIMEOUT)->toBeLessThan($jobTimeouts->min() / 2);
})->with(['postgres', 'mysql', 'mariadb', 'mongodb', 'valkey', 'redis']);

it('says how to retry a failed cleanup in a whole sentence', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);
    fakeSsh()->failWith(new RuntimeException('connection reset'));

    resolve(EndMigration::class)->handle($migration, cutover: false, force: true);

    expect($database->serverLogs()->where('name', 'Clean up migration')->sole()->output)
        ->toContain(': connection reset. The migration has ended, but its copy or replication from the source may still be running. Use Clean up again on the Migrations tab.');
});
