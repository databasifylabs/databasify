<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('reads the current status under the row lock even from a stale copy', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $stale = $database->fresh();
    $database->update(['status' => DatabaseStatus::Upgrading]);

    expect(fn () => DB::transaction(fn () => $stale->refreshForUpdate()->ensureIdle()))
        ->toThrow(ValidationException::class, 'main is busy upgrading, try again when it finishes.');
});

it('refuses new work while data is migrating in', function () {
    $database = migratingDatabase('mysql');
    migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);

    expect(fn () => $database->ensureIdle())->toThrow(ValidationException::class, 'migrating data in');

    $database->migrations()->update(['status' => MigrationStatus::Completed]);

    expect(fn () => $database->ensureIdle())->not->toThrow(ValidationException::class);
});
