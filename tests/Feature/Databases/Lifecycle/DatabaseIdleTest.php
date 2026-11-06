<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Middleware\ReleasesDatabaseOperation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

it('allows only one operation per database even when two requests race', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $stale = $database->fresh();
    $database->startOperation('First');

    expect(fn () => $stale->startOperation('Second'))->toThrow(ValidationException::class, 'main is busy with')
        ->and($database->fresh()->operation)->toBe('First');

    $database->finishOperation();
    $database->startOperation('Third');

    expect($database->fresh()->operation)->toBe('Third');
});

it('frees the database and its stop request once the job finishes', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->startOperation('Upgrade');
    $database->requestStop();

    $result = (new ReleasesDatabaseOperation($database))->handle(new stdClass, fn (object $job): string => 'done');

    expect($result)->toBe('done')
        ->and($database->fresh())->operation->toBeNull()->isStopRequested()->toBeFalse();
});

it('honours stop again once a new operation starts after a cleanup that never finished', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Cache::put("databases.{$database->id}.cleanup", true, 86400);

    $database->startOperation('Next');
    $database->requestStop();

    expect($database->isStopRequested())->toBeTrue();
});

it('drops a leftover cleanup flag when an operation finishes', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->startOperation('Add');
    Cache::put("databases.{$database->id}.cleanup", true, 86400);

    $database->finishOperation();

    expect(Cache::has("databases.{$database->id}.cleanup"))->toBeFalse();
});

it('frees the database when the job throws', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->startOperation('Upgrade');

    expect(fn () => (new ReleasesDatabaseOperation($database))->handle(new stdClass, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class, 'boom')
        ->and($database->fresh()->operation)->toBeNull();
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
    $database->startOperation('Upgrade');

    $this->post("/databases/{$database->id}/stop")->assertSessionHasNoErrors();

    expect($database->fresh()->isStopRequested())->toBeTrue();
});

it('has nothing to stop on an idle database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('postgres', 1);

    $this->post("/databases/{$database->id}/stop")->assertSessionHasErrors(['database' => 'main is not running anything.']);
});
