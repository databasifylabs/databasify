<?php

declare(strict_types=1);

use App\Actions\Databases\Recovery\RecordRecoveryStatus;
use App\DTOs\Recovery\ArchiveStatus;
use App\Enums\Recovery\ArchiveHealth;
use App\Events\DatabaseAlertRaised;
use App\Models\Database;
use App\Models\PointInTimeRecovery;
use Illuminate\Support\Facades\Event;

test('an archive failure sends one alert and repeated failed checks are quiet', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $recovery = PointInTimeRecovery::factory()->restorable()->for(Database::factory())->create();
    $record = app(RecordRecoveryStatus::class);

    $record->handle($recovery, ArchiveStatus::unreadable('private credential must not be emailed'));
    $record->handle($recovery->refresh(), ArchiveStatus::unreadable('still inaccessible'));

    expect($recovery->refresh()->health)->toBe(ArchiveHealth::Failing);
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 1);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => str_ends_with($alert->subject, 'backup archive needs attention') && ! str_contains($alert->message, 'private credential'));
});

test('a healthy archive after a failure sends a recovery notification once', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $recovery = PointInTimeRecovery::factory()->restorable()->for(Database::factory())->create(['health' => ArchiveHealth::Failing]);
    $status = new ArchiveStatus(2, now()->subDays(2), now(), now()->subHour(), null);
    $record = app(RecordRecoveryStatus::class);

    $record->handle($recovery, $status);
    $record->handle($recovery->refresh(), $status);

    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 1);
    Event::assertDispatched(DatabaseAlertRaised::class, fn (DatabaseAlertRaised $alert): bool => str_ends_with($alert->subject, 'backup archive is healthy again'));
});

test('initial pending and healthy archives do not send failure or recovery emails', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $recovery = PointInTimeRecovery::factory()->for(Database::factory())->create();
    $record = app(RecordRecoveryStatus::class);

    $record->handle($recovery, new ArchiveStatus(0, null, null, null, null));
    $record->handle($recovery->refresh(), new ArchiveStatus(1, now()->subHour(), now(), now(), null));

    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

test('an overdue first base backup fails after the grace period', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $recovery = PointInTimeRecovery::factory()->for(Database::factory())->create(['created_at' => now()->subHours(27)]);

    app(RecordRecoveryStatus::class)->handle($recovery, new ArchiveStatus(0, null, null, null, null));

    expect($recovery->refresh()->health)->toBe(ArchiveHealth::Failing)
        ->and($recovery->problem)->toContain('26 hours');
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 1);
});

test('fresh log uploads do not hide an overdue daily base backup', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $recovery = PointInTimeRecovery::factory()->restorable()->for(Database::factory())->create();

    app(RecordRecoveryStatus::class)->handle($recovery, new ArchiveStatus(2, now()->subDays(3), now(), now()->subHours(27), null));

    expect($recovery->refresh()->health)->toBe(ArchiveHealth::Failing);
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 1);
});

test('a deleted recovery configuration is ignored without sending alerts', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $recovery = PointInTimeRecovery::factory()->for(Database::factory())->create();
    $recovery->delete();

    app(RecordRecoveryStatus::class)->handle($recovery, ArchiveStatus::unreadable('gone'));

    Event::assertNotDispatched(DatabaseAlertRaised::class);
});

test('a base backup exactly at the grace boundary is not overdue', function () {
    $this->freezeTime();
    $status = new ArchiveStatus(1, now()->subDays(2), now(), now()->subHours(26), null);

    expect($status->checkingDailyBackupAt(now(), now()->subDays(3))->healthAt(now()))->toBe(ArchiveHealth::Healthy);
});

test('stale recovery coverage raises an alert even with a recent base backup', function () {
    Event::fake([DatabaseAlertRaised::class]);
    $recovery = PointInTimeRecovery::factory()->restorable()->for(Database::factory())->create(['latest_restorable_at' => now()->subHour()]);

    app(RecordRecoveryStatus::class)->handle($recovery, new ArchiveStatus(2, now()->subDays(2), now()->subHour(), now()->subHours(2), null));

    expect($recovery->refresh()->health)->toBe(ArchiveHealth::Failing);
    Event::assertDispatchedTimes(DatabaseAlertRaised::class, 1);
});
