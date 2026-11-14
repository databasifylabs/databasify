<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\EndFailedNodeOperation;
use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Engines\Data\Operation;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\OperationState;
use App\Enums\Servers\ServerLogStatus;
use App\Events\DatabaseAlertRaised;
use App\Jobs\Databases\RefreshDatabaseStatusJob;
use App\Services\Databases\EngineTasks;
use Illuminate\Support\Facades\Event;

it('does not fail a read-only node running log when a main operation ends', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $reader->markBusy(DatabaseStatus::Updating);
    $log = $reader->openLog("{$reader->server->name}: applying settings");
    $database->markBusy(DatabaseStatus::Updating);

    $database->markReady();

    expect($log->fresh()?->status)->toBe(ServerLogStatus::Running);
});

it('stops only the read-only node own engine task when its operation fails', function () {
    Event::fake([DatabaseAlertRaised::class]);
    useRecordingDrivers();
    $database = idleDatabase('postgres', 1);
    $member = $database->seed();
    $reader = withReadOnlyNode($database);
    $member->update(['task_key' => "run1.{$member->id}.ready"]);
    $reader->update(['task_key' => "run2.{$reader->id}.ready"]);
    $reader->markBusy(DatabaseStatus::Updating);

    resolve(EndFailedNodeOperation::class)->handle($reader->fresh(), 'Apply settings', new RuntimeException('broke'));

    expect(recordedArgs('task.stop'))->toHaveCount(1)
        ->and(recordedArgs('task.stop')[0]['args'][0])->toBe("run2.{$reader->id}.ready")
        ->and($member->fresh()?->task_key)->not->toBeNull()
        ->and($reader->fresh()?->status)->toBe(DatabaseStatus::Ready);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->subject === "main: Apply settings on {$reader->displayName()} failed");
});

it('waits only on its own node while a read-only node operation runs', function () {
    useRecordingDrivers();
    $database = idleDatabase('postgres', 1);
    $member = $database->seed();
    $reader = withReadOnlyNode($database);
    $member->update(['task_key' => "run1.{$member->id}.copy"]);
    startedTask("run1.{$member->id}.copy");
    answerRead('task.status', fn (string $key) => new Operation($key, 'task', OperationState::Running, '', null, null));

    expect(resolve(EngineTasks::class)->stillRunning($reader))->toBeFalse();
});

it('does not fail a read-only node running log when a main operation fails', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $reader->markBusy(DatabaseStatus::Updating);
    $log = $reader->openLog("{$reader->server->name}: retuning");
    $database->markBusy(DatabaseStatus::Updating);

    resolve(EndFailedOperation::class)->handle($database, 'Apply settings', new RuntimeException('broke'));

    expect($log->fresh()?->status)->toBe(ServerLogStatus::Running);
});

it('does not read or alert on a read-only node while it runs its own operation', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $ssh = fakeSsh();
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    answerNodeStatus(answerNodeStatus($ssh, $database->seed()->server, 'primary'), $reader->server, null);
    $reader->markBusy(DatabaseStatus::Rebooting);

    runJob(new RefreshDatabaseStatusJob($database));
    runJob(new RefreshDatabaseStatusJob($database));
    runJob(new RefreshDatabaseStatusJob($database));

    expect($reader->fresh()?->role)->toBe(NodeRole::Replica)
        ->and(collect(recordedArgs('nodes.status'))->pluck('server'))->not->toContain($reader->server->name);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});
