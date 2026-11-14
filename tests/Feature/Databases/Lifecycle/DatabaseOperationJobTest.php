<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Upgrades\RollOutImage;
use App\Engines\Data\Operation;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\OperationState;
use App\Enums\Servers\ServerLogStatus;
use App\Events\DatabaseAlertRaised;
use App\Exceptions\Databases\OperationStopped;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineTaskRunning;
use App\Exceptions\Engines\EngineUnavailable;
use App\Jobs\Databases\AddNodeJob;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Server;
use App\Services\Databases\EngineTasks;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

function upgradingDatabase(): Database
{
    $database = idleDatabase('postgres', 1);
    $database->update(['status' => DatabaseStatus::Upgrading]);

    return $database;
}

it('fails running logs, alerts and returns to ready when an operation fails', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = upgradingDatabase();
    $log = $database->seed()->openLog('db-1: upgrade');

    resolve(EndFailedOperation::class)->handle($database, 'Upgrade', new RuntimeException('the image did not start'));

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($log->fresh()?->status)->toBe(ServerLogStatus::Failed);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->subject === 'main: Upgrade failed' && $alert->message === 'the image did not start');
});

function taskStates(OperationState ...$states): void
{
    answerRead('task.status', function (string $key) use (&$states): Operation {
        return new Operation($key, 'task', array_shift($states) ?? OperationState::Succeeded, '', null, 'copy broke');
    });
}

function startTaskOn(DatabaseNode $node): void
{
    resolve(EngineTasks::class)->await($node, 'run1', 'ready', 'waiting for the node', fn (string $key): Operation => $node->driver()->nodes()->ready($key, true));
}

it('returns once the engine task succeeds within the short wait', function () {
    Sleep::fake();
    $node = upgradingDatabase()->seed();
    taskStates(OperationState::Running, OperationState::Succeeded);

    startTaskOn($node);

    $log = $node->server->serverLogs()->latest()->first();
    expect($node->fresh()?->task_key)->toBeNull()
        ->and($log?->name)->toBe("{$node->server->name}: waiting for the node")
        ->and($log?->status)->toBe(ServerLogStatus::Succeeded);
});

it('saves the task key and gives the worker back when the task outlives the short wait', function () {
    Sleep::fake();
    $node = upgradingDatabase()->seed();
    taskStates(...array_fill(0, 20, OperationState::Running));

    expect(fn () => startTaskOn($node))->toThrow(EngineTaskRunning::class);

    expect($node->fresh()?->task_key)->toBe("run1.{$node->id}.ready")
        ->and($node->server->serverLogs()->latest()->first()?->status)->toBe(ServerLogStatus::Running);
});

it('uses the same key on the next run so the engine does not start the work again', function () {
    Sleep::fake();
    $node = upgradingDatabase()->seed();
    taskStates(...[...array_fill(0, 20, OperationState::Running), OperationState::Succeeded]);
    rescue(fn () => startTaskOn($node), report: false);

    startTaskOn($node);

    expect(collect(useRecordingDrivers()->argsOf('nodes.ready'))->pluck(0)->unique()->all())->toBe(["run1.{$node->id}.ready"])
        ->and($node->server->serverLogs()->count())->toBe(1)
        ->and($node->fresh()?->task_key)->toBeNull();
});

it('keeps waiting while the server cannot be reached', function () {
    $node = upgradingDatabase()->seed();
    $node->update(['task_key' => "run1.{$node->id}.copy"]);
    startedTask("run1.{$node->id}.copy");
    answerRead('task.status', fn () => throw new EngineUnavailable('unreachable'));

    expect(resolve(EngineTasks::class)->stillRunning($node->database))->toBeTrue()
        ->and($node->fresh()?->task_key)->not->toBeNull();
});

it('forgets an engine task that ended while the job waited in the queue', function () {
    useRecordingDrivers();
    $node = upgradingDatabase()->seed();
    $node->update(['task_key' => "run1.{$node->id}.copy"]);
    startedTask("run1.{$node->id}.copy");

    expect(resolve(EngineTasks::class)->stillRunning($node->database))->toBeFalse()
        ->and($node->fresh()?->task_key)->toBeNull();
});

it('stops the running engine task when stop is pressed while the job waits in the queue', function () {
    useRecordingDrivers();
    $database = upgradingDatabase();
    $node = $database->seed();
    $node->update(['task_key' => "run1.{$node->id}.copy"]);
    startedTask("run1.{$node->id}.copy");
    $database->requestStop();

    expect(fn () => resolve(EngineTasks::class)->stillRunning($database))->toThrow(OperationStopped::class);

    expect(collect(recordedCalls())->filter(fn (string $call): bool => $call === 'task.stop'))->toHaveCount(1)
        ->and($node->fresh()?->task_key)->toBeNull();
});

it('puts an operation job back on the queue while its engine task runs', function () {
    Queue::fake();
    $database = upgradingDatabase();
    $node = $database->seed();
    $node->update(['task_key' => "run1.{$node->id}.ready"]);
    startedTask("run1.{$node->id}.ready");
    taskStates(OperationState::Running);

    $job = (new UpgradeDatabaseJob($database))->withFakeQueueInteractions();
    $job->handle(resolve(RollOutImage::class), resolve(EngineTasks::class));

    $job->assertReleased(delay: 30);
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

it('leaves a database that is no longer busy alone when an old job fails late', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('postgres', 1);

    resolve(EndFailedOperation::class)->handle($database, 'Upgrade', new RuntimeException('redelivered after a restart'));

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Ready);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

it('does not report a stop as a failure', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = upgradingDatabase();

    resolve(EndFailedOperation::class)->handle($database, 'Upgrade', new OperationStopped('Stopped by you.'));

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

it('stops the running engine task before it erases a node that failed to join', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $ssh = fakeEngine();
    $database = idleDatabase('postgres', 2);
    $joiner = $database->addNode(Server::factory()->ready()->create()->id);
    $database->update(['status' => DatabaseStatus::Scaling]);
    $joiner->update(['task_key' => "run1.{$joiner->id}.copy"]);
    startedTask("run1.{$joiner->id}.copy");

    (new AddNodeJob($database, $joiner))->failed(new RuntimeException('timed out'));

    $stoppedAt = collect(useRecordingDrivers()->calls)->firstWhere('call', 'task.stop')['at'] ?? null;
    $removedAt = collect($ssh->runs)->keys()->first(fn (int $index): bool => str_contains($ssh->scriptAt($index), '| databasify host remove '));
    expect($stoppedAt)->not->toBeNull()
        ->and($removedAt)->toBeGreaterThanOrEqual($stoppedAt);
});

it('fails logs an operation left running when the operation ends', function () {
    $database = upgradingDatabase();
    $log = $database->seed()->openLog('db-1: rebooting');

    $database->markReady();

    expect($log->fresh()?->status)->toBe(ServerLogStatus::Failed);
});

it('treats a server without the engine binary or a running engine as having no task yet', function (int $exit) {
    $node = engineNode();
    fakeSsh()->respond('', $exit);

    expect(resolve(EngineTasks::class)->started($node, 'run1', 'ready'))->toBeFalse();
})->with(['binary missing' => 127, 'engine not running' => 69]);

it('keeps waiting when the status call breaks off without a reply', function () {
    $node = engineNode();
    $node->update(['task_key' => "run1.{$node->id}.copy"]);
    fakeSsh()->respond('', 1);

    expect(resolve(EngineTasks::class)->stillRunning($node->database))->toBeTrue()
        ->and($node->fresh()?->task_key)->not->toBeNull();
});

it('fails instead of starting the work again when the engine lost a task', function () {
    useRecordingDrivers();
    $node = upgradingDatabase()->seed();
    $node->update(['task_key' => "run1.{$node->id}.copy"]);

    expect(fn () => resolve(EngineTasks::class)->stillRunning($node->database))->toThrow(EngineError::class);

    expect($node->fresh()?->task_key)->toBeNull();
});

it('saves the key of every started task before it waits on any of them', function () {
    Sleep::fake();
    $database = upgradingDatabase();
    [$first, $second] = [$database->seed(), withReadOnlyNode($database)->load('server')];
    taskStates(OperationState::Failed, OperationState::Failed);

    expect(fn () => resolve(EngineTasks::class)->awaitEach(collect([$first, $second]), 'run1', 'rejoin', 'rejoining the cluster', fn (DatabaseNode $node, string $key): Operation => $node->driver()->cluster()->rejoin($key, readOnly: false)))
        ->toThrow(EngineError::class);

    expect($second->fresh()?->task_key)->toBe("run1.{$second->id}.rejoin");
});

it('does not start an engine task once stop was pressed', function () {
    useRecordingDrivers();
    $database = upgradingDatabase();
    $node = $database->seed();
    $database->requestStop();

    expect(fn () => startTaskOn($node))->toThrow(OperationStopped::class);

    expect(recordedCalls())->not->toContain('nodes.ready');
});
