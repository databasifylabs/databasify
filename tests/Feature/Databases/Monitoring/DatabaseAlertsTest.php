<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Events\DatabaseAlertRaised;
use App\Models\User;
use App\Notifications\DatabaseAlert;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

it('raises an alert when a database degrades and when it recovers', function () {
    $database = idleDatabase('valkey', 2);
    $database->update(['status' => DatabaseStatus::Ready]);
    Event::fake([DatabaseAlertRaised::class]);
    [$primary, $replica] = $database->nodes()->orderBy('position')->get()->map->server;

    answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary'), $replica, null);
    resolve(RefreshDatabaseStatus::class)->handle($database);
    answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary'), $replica, 'replica');
    resolve(RefreshDatabaseStatus::class)->handle($database->fresh() ?? $database);

    $down = $database->nodes()->with('server')->get()[1]->server->name;
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->subject === 'main is degraded' && str_contains($alert->message, "Unreachable: {$down}."));
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => $alert->subject === 'main is healthy again');
});

it('emails every user about an alert', function () {
    Notification::fake();
    $user = User::factory()->create();

    event(new DatabaseAlertRaised(idleDatabase('valkey', 1, DatabaseMode::Single), 'main is degraded', 'No primary is serving writes.'));

    Notification::assertSentTo($user, DatabaseAlert::class, fn (DatabaseAlert $notification): bool => $notification->subject === 'main is degraded');
});
