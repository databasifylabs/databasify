<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\CreateSchema;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Databases\OperationStopped;
use Illuminate\Validation\ValidationException;

it('runs sql on whichever node is primary now', function () {
    $database = idleDatabase('mysql', 3);
    markRoles($database, NodeRole::Replica, NodeRole::Primary, NodeRole::Replica);
    $primary = $database->nodes()->get()[1];
    $ssh = fakeSsh();

    resolve(CreateSchema::class)->handle($database, 'x');

    expect($ssh->runs)->toHaveCount(1)
        ->and($ssh->runs[0]['server'])->toBe($primary->server->name)
        ->and(sqlSentIn($ssh->scriptAt(0)))->toStartWith('CREATE DATABASE IF NOT EXISTS `x`')
        ->and($database->serverLogs()->sole()->status)->toBe(ServerLogStatus::Succeeded)
        ->and($database->fresh()->operation)->toBeNull();
});

it('fails the operation without refreshing the status when no node is the primary', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    markRoles($database, NodeRole::Replica);
    $ssh = fakeSsh();

    expect(fn () => resolve(CreateSchema::class)->handle($database, 'x'))->toThrow(ValidationException::class, 'Create database x failed: main has no healthy primary.')
        ->and($ssh->runs)->toBe([])
        ->and($database->fresh()->operation)->toBeNull()
        ->and($database->schemas()->pluck('name')->all())->toBe(['defaultdb']);
});

it('refuses sql while the database is busy or migrating', function () {
    $busy = newDatabase('mysql', 1, DatabaseMode::Single, 'busy');
    $migrating = migratingDatabase('mysql');
    migrationOf($migrating, MigrationMode::Continuous);
    $ssh = fakeSsh();

    expect(fn () => resolve(CreateSchema::class)->handle($busy, 'x'))->toThrow(ValidationException::class, 'busy is busy with Create MySQL database')
        ->and(fn () => resolve(CreateSchema::class)->handle($migrating, 'x'))->toThrow(ValidationException::class, 'is migrating data in')
        ->and($ssh->runs)->toBe([])
        ->and($busy->fresh()->operation)->toBe('Create MySQL database');
});

it('refuses account changes on an engine without accounts', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $ssh = fakeSsh();

    expect(fn () => resolve(CreateSchema::class)->handle($database, 'x'))
        ->toThrow(ValidationException::class, 'Valkey databases have no users or databases to manage.')
        ->and($ssh->runs)->toBe([]);
});

it('lets a stop end the best-effort grants instead of logging them as failed', function (Closure $grant) {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    markRoles($database, NodeRole::Primary);
    $database->requestStop();
    $ssh = fakeSsh();

    expect(fn () => $grant($database))->toThrow(OperationStopped::class);

    expect($ssh->runs)->toBeEmpty()
        ->and($database->serverLogs()->where('name', 'like', 'Grant %')->exists())->toBeFalse();
})->with([
    'replication' => [fn ($database) => $database->provider()->accounts()?->regrantReplicationToDefaultUser()],
    'migrated databases' => [fn ($database) => $database->provider()->accounts()?->grantDefaultUserOn(['shop'])],
]);
