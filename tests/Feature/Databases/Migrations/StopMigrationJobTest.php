<?php

declare(strict_types=1);

use App\Engines\Data\Operation;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\OperationState;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Exceptions\Engines\EngineError;
use App\Jobs\Databases\StopMigrationJob;
use App\Models\DatabaseMigration;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    fakeEngine();
    $this->database = migratingDatabase('mongodb');
    $this->database->markBusy(DatabaseStatus::Migrating);
    $this->migration = migrationOf($this->database, MigrationMode::Continuous, MigrationStatus::Syncing);
});

function stopTaskStates(OperationState ...$states): void
{
    answerRead('task.status', function (string $key) use (&$states): Operation {
        return new Operation($key, 'task', array_shift($states) ?? OperationState::Succeeded, '', null, 'ClusterSync could not finalize: duplicate key error');
    });
}

it('finishes a cutover whose engine stop outlives a worker run without starting the stop twice', function () {
    stopTaskStates(...array_fill(0, 20, OperationState::Running));
    $job = new StopMigrationJob($this->migration, cutover: true);

    runJob($job);

    expect($this->migration->fresh())->status->toBe(MigrationStatus::Completed)
        ->and($this->migration->fresh()?->cleanupFailed())->toBeTrue()
        ->and($this->database->fresh()?->status)->toBe(DatabaseStatus::Migrating);

    stopTaskStates();
    runJob($job);

    expect($this->migration->fresh()?->cleanupFailed())->toBeFalse()
        ->and($this->database->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and(collect(useRecordingDrivers()->argsOf('migration.stop'))->pluck(0)->unique()->all())->toBe(["{$job->run}.{$this->database->seed()->id}.migration-stop"]);
});

it('keeps a migration whose engine stop failed cleanable and says why', function () {
    stopTaskStates(OperationState::Failed);

    expect(fn () => runJob(new StopMigrationJob($this->migration, cutover: true)))->toThrow(EngineError::class, 'duplicate key error');

    $failure = $this->database->seed()->server->serverLogs()->where('name', 'Clean up migration')->sole();
    expect($this->migration->fresh())->toBeInstanceOf(DatabaseMigration::class)->status->toBe(MigrationStatus::Completed)
        ->and($this->migration->fresh()?->cleanupFailed())->toBeTrue()
        ->and($failure->output)->toContain('duplicate key error')->toContain('Clean up again')
        ->and($this->database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});
