<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('reports busy for every working status', function (DatabaseStatus $status, bool $busy) {
    expect($status->isBusy())->toBe($busy);
})->with([
    [DatabaseStatus::Upgrading, true],
    [DatabaseStatus::Pending, true],
    [DatabaseStatus::Deleting, true],
    [DatabaseStatus::Ready, false],
    [DatabaseStatus::Failed, false],
]);

it('knows which statuses are still setting up', function (DatabaseStatus $status, bool $settingUp) {
    expect($status->isSettingUp())->toBe($settingUp);
})->with([
    [DatabaseStatus::Pending, true],
    [DatabaseStatus::Creating, true],
    [DatabaseStatus::Upgrading, false],
    [DatabaseStatus::Ready, false],
]);

it('refuses work while the database is busy', function () {
    $database = idleDatabase('postgres', 1);
    $database->update(['status' => DatabaseStatus::Upgrading]);

    expect(fn () => $database->ensureIdle())
        ->toThrow(ValidationException::class, 'main is busy upgrading, try again when it finishes.');
});

it('ends an operation as ready or failed', function () {
    $database = idleDatabase('postgres', 1);
    $database->update(['status' => DatabaseStatus::Upgrading]);

    $database->markReady();

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Ready);

    $database->markFailed();

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Failed);
});

it('drops the operation columns and the flows table', function () {
    expect(Schema::hasColumn('databases', 'operation'))->toBeFalse()
        ->and(Schema::hasColumn('databases', 'operation_started_at'))->toBeFalse()
        ->and(Schema::hasTable('database_flows'))->toBeFalse();
});
