<?php

declare(strict_types=1);

use App\Engines\Data\Operation;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\OperationState;
use App\Enums\Servers\ServerLogStatus;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Databases\OperationStopped;
use App\Exceptions\Engines\EngineError;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Tests\Support\ScriptedOperationJob;

function upgradingDatabase(): Database
{
    $database = idleDatabase('postgres', 1);
    $database->update(['status' => DatabaseStatus::Upgrading]);

    return $database;
}

it('marks the database ready after the job succeeds', function () {
    $database = upgradingDatabase();
    ScriptedOperationJob::script(fn () => null);

    dispatch(new ScriptedOperationJob($database));

    expect(ScriptedOperationJob::$runs)->toBe(1)
        ->and($database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('skips the job when the database is no longer in its status', function () {
    $database = idleDatabase('postgres', 1);
    ScriptedOperationJob::script(fn () => null);

    dispatch(new ScriptedOperationJob($database));

    expect(ScriptedOperationJob::$runs)->toBe(0)
        ->and($database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('ends the operation without running when stopped while queued', function () {
    $database = upgradingDatabase();
    $database->requestStop();
    ScriptedOperationJob::script(fn () => null);

    dispatch(new ScriptedOperationJob($database));

    expect(ScriptedOperationJob::$runs)->toBe(0)
        ->and($database->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($database->isStopRequested())->toBeFalse();
});

it('fails running logs, alerts and returns to ready when the job fails', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = upgradingDatabase();
    $log = $database->seed()->openLog('db-1: upgrade');
    ScriptedOperationJob::script(fn () => throw new RuntimeException('the image did not start'));

    expect(fn () => dispatch(new ScriptedOperationJob($database)))->toThrow(RuntimeException::class);

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($log->fresh()?->status)->toBe(ServerLogStatus::Failed);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->subject === 'main: Scripted failed' && $alert->message === 'the image did not start');
});

function taskStates(OperationState ...$states): void
{
    answerRead('task.status', function (string $key) use (&$states): Operation {
        return new Operation($key, 'task', array_shift($states) ?? OperationState::Succeeded, '', null, 'copy broke');
    });
}

function startTaskOn(DatabaseNode $node): void
{
    resolve(EngineTasks::class)->await($node, 'waiting for the node', fn (string $key): Operation => $node->driver()->nodes()->ready($key, true));
}

it('waits for an engine task until it succeeds', function () {
    Sleep::fake();
    $node = upgradingDatabase()->seed();
    taskStates(OperationState::Running, OperationState::Running, OperationState::Succeeded);

    startTaskOn($node);

    $log = $node->server->serverLogs()->latest()->first();
    expect(collect(recordedCalls())->filter(fn (string $call): bool => $call === 'task.status'))->toHaveCount(3)
        ->and($log?->name)->toBe("{$node->server->name}: waiting for the node")
        ->and($log?->status)->toBe(ServerLogStatus::Succeeded);
    Sleep::assertSleptTimes(3);
});

it('fails when the engine task fails', function () {
    Sleep::fake();
    $node = upgradingDatabase()->seed();
    taskStates(OperationState::Running, OperationState::Failed);

    expect(fn () => startTaskOn($node))->toThrow(EngineError::class, 'copy broke');

    expect($node->server->serverLogs()->latest()->first()?->status)->toBe(ServerLogStatus::Failed);
});

it('stops the engine task when stop is requested', function () {
    Sleep::fake();
    $database = upgradingDatabase();
    $node = $database->seed();
    taskStates(OperationState::Running, OperationState::Running);
    Sleep::whenFakingSleep(fn () => $database->requestStop());

    expect(fn () => startTaskOn($node))->toThrow(OperationStopped::class);

    expect(collect(recordedCalls())->filter(fn (string $call): bool => $call === 'task.stop'))->toHaveCount(1);
});

it('leaves a database that moved on alone when an old job fails late', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = upgradingDatabase();
    $database->markReady();
    $database->markBusy(DatabaseStatus::Rebooting);

    (new ScriptedOperationJob($database))->failed(new RuntimeException('redelivered after a restart'));

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Rebooting);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

it('does not report a stop as a failure', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = upgradingDatabase();

    (new ScriptedOperationJob($database))->failed(new OperationStopped('Stopped by you.'));

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Ready);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

it('refuses every operation but delete and retry on a database whose setup failed', function () {
    $database = idleDatabase('postgres', 1);
    $database->markFailed();

    expect(fn () => $database->markBusy(DatabaseStatus::Upgrading))
        ->toThrow(ValidationException::class, 'main failed to set up, retry the setup or delete it.');

    $database->markBusy(DatabaseStatus::Deleting);

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Deleting);
});
