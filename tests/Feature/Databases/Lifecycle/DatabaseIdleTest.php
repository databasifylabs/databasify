<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Middleware\RunsDatabaseOperation;
use App\Models\Database;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('reads the current status under the row lock even from a stale copy', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $stale = $database->fresh();
    $database->update(['status' => DatabaseStatus::Upgrading]);

    expect(fn () => DB::transaction(fn () => $stale->refreshForUpdate()->ensureIdle()))
        ->toThrow(ValidationException::class, 'main is busy upgrading, try again when it finishes.');
});

it('frees the database once the job finishes', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['status' => DatabaseStatus::Upgrading]);

    $result = (new RunsDatabaseOperation($database, DatabaseStatus::Upgrading, fn (Database $database) => $database->abandonOperation()))->handle(new stdClass, fn (object $job): string => 'done');

    expect($result)->toBe('done')
        ->and($database->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('drops a leftover cleanup flag when an operation ends, so stop works again', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Cache::put("databases.{$database->id}.cleanup", true, 86400);

    $database->markReady();
    $database->requestStop();

    expect(Cache::has("databases.{$database->id}.cleanup"))->toBeFalse()
        ->and($database->isStopRequested())->toBeTrue();
});

it('refuses new work while data is migrating in', function () {
    $database = migratingDatabase('mysql');
    migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);

    expect(fn () => $database->ensureIdle())->toThrow(ValidationException::class, 'migrating data in');

    $database->migrations()->update(['status' => MigrationStatus::Completed]);

    expect(fn () => $database->ensureIdle())->not->toThrow(ValidationException::class);
});

it('asks a busy database to stop from its page', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('postgres', 1);
    $database->update(['status' => DatabaseStatus::Upgrading]);

    $this->post(projectUrl("/databases/{$database->id}/stop"))->assertSessionHasNoErrors();

    expect($database->fresh()->isStopRequested())->toBeTrue();
});

it('has nothing to stop on an idle database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('postgres', 1);

    $this->post(projectUrl("/databases/{$database->id}/stop"))->assertSessionHasErrors(['database' => 'main is not running anything.']);
});
