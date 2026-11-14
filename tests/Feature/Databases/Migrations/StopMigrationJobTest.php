<?php

declare(strict_types=1);

use App\Engines\Data\Operation;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\OperationState;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Engines\EngineError;
use App\Jobs\Databases\StopMigrationJob;
use App\Models\DatabaseMigration;
use Illuminate\Support\Facades\Event;
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
        ->and(collect(useRecordingDrivers()->argsOf('migration.stop'))->unique(0)->values()->all())->toBe([["{$job->run}.{$this->database->seed()->id}.migration-stop", true]]);
});

it('says why the cleanup failed when the engine stop fails after a release', function () {
    stopTaskStates(...array_fill(0, 20, OperationState::Running));
    $job = new StopMigrationJob($this->migration, cutover: true);
    runJob($job);
    stopTaskStates(OperationState::Failed);

    expect(fn () => runJob($job))->toThrow(EngineError::class, 'duplicate key error');

    expect($this->database->seed()->server->serverLogs()->where('name', 'Clean up migration')->sole()->output)->toContain('duplicate key error')->toContain('Clean up again')
        ->and($this->migration->fresh()?->cleanupFailed())->toBeTrue()
        ->and($this->database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('stops a migration before it finished without a cutover', function () {
    runJob(new StopMigrationJob($this->migration, cutover: false));

    expect($this->migration->fresh())->status->toBe(MigrationStatus::Stopped)
        ->and(useRecordingDrivers()->argsOf('migration.stop')[0][1])->toBeFalse();
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

it('runs a stop that was queued before the run id existed', function () {
    $current = serialize(new StopMigrationJob($this->migration, cutover: true));
    $queuedBeforeUpdate = (string) preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):\{(.*)s:3:"run";s:26:"[a-z0-9]{26}";(.*)$/s', fn (array $part): string => "O:{$part[1]}:\"{$part[2]}\":".($part[3] - 1).":{{$part[4]}{$part[5]}", $current);

    expect($queuedBeforeUpdate)->not->toBe($current);

    runJob(unserialize($queuedBeforeUpdate));

    expect($this->migration->fresh())->status->toBe(MigrationStatus::Completed)
        ->and($this->migration->fresh()?->cleanupFailed())->toBeFalse()
        ->and($this->database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('fails a migration whose requested cutover never ran instead of leaving it syncing', function () {
    Event::fake([DatabaseAlertRaised::class]);

    (new StopMigrationJob($this->migration, cutover: true))->failed(new RuntimeException('the worker crashed'));

    expect($this->migration->fresh())->status->toBe(MigrationStatus::Failed)->detail->toBe('Finishing failed: the worker crashed.')
        ->and($this->migration->fresh()?->cleanupFailed())->toBeTrue()
        ->and($this->database->seed()->server->serverLogs()->where('name', 'Clean up migration')->sole()->output)->toContain('the worker crashed')->toContain('Clean up again')
        ->and($this->database->fresh()?->status)->toBe(DatabaseStatus::Ready);
    Event::assertDispatched(DatabaseAlertRaised::class);
});
