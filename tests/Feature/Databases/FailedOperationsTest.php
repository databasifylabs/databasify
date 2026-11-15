<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\EndFailedNodeOperation;
use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\DTOs\Databases\NodeStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Enums\Servers\ServerLogStatus;
use App\Events\DatabaseAlertRaised;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use Illuminate\Support\Facades\Event;
use Tests\Support\RecordingDriver;

test('a failed operation fails its running logs, raises an alert and frees the database', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = Database::factory()->status(DatabaseStatus::Upgrading)->create(['name' => 'orders']);
    $node = DatabaseNode::factory()->for($database)->create();
    $log = ServerLog::factory()->for($node->server)->create(['database_id' => $database->id, 'status' => ServerLogStatus::Running, 'exit_code' => null]);
    RecordingDriver::fake()->on('nodes.status', new NodeStatus(NodeRole::Primary, '18.1'));

    app(EndFailedOperation::class)->handle($database, 'Upgrade', new RuntimeException('image pull failed'));

    expect($database->refresh()->status)->toBe(DatabaseStatus::Ready)
        ->and($log->refresh()->status)->toBe(ServerLogStatus::Failed);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert) => $alert->subject === 'orders: Upgrade failed' && $alert->message === 'image pull failed');
});

test('a failed setup leaves the database failed', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = Database::factory()->status(DatabaseStatus::Creating)->create();

    app(EndFailedOperation::class)->handle($database, 'Setup', null, markFailed: true);

    expect($database->refresh()->status)->toBe(DatabaseStatus::Failed);
});

test('a database that is no longer busy is left alone', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $database = Database::factory()->create();

    app(EndFailedOperation::class)->handle($database, 'Upgrade', new RuntimeException('late failure'));

    expect($database->refresh()->status)->toBe(DatabaseStatus::Ready);
    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

test('a failed node operation frees only that node', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports', 'status' => DatabaseStatus::Rebooting]);

    app(EndFailedNodeOperation::class)->handle($readOnly, 'Reboot', new RuntimeException('server did not come back'));

    expect($readOnly->refresh()->status)->toBe(DatabaseStatus::Ready);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert) => str_contains($alert->subject, 'Reboot on orders-reports failed'));
});
