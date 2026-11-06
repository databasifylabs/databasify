<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Migrations\MigrationMode;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

it('lets a server outside any database through', function () {
    (Server::factory()->ready()->create())->ensureDatabasesSpareIt();
})->throwsNoExceptions();

it('lets the primary of a single node database through', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    markRoles($database, NodeRole::Primary);

    $database->seed()->server->ensureDatabasesSpareIt();
})->throwsNoExceptions();

it('refuses the primary of a database with other members', function () {
    $database = idleDatabase('valkey', 2, name: 'cache');
    markRoles($database, NodeRole::Primary, NodeRole::Replica);

    expect(fn () => $database->seed()->server->ensureDatabasesSpareIt())
        ->toThrow(ValidationException::class, 'is the primary of cache, fail over first.');
});

it('refuses any member of a busy database', function () {
    $database = idleDatabase('valkey', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $database->startOperation('Upgrade');
    $replica = $database->nodes()->where('role', NodeRole::Replica)->firstOrFail();

    expect(fn () => $replica->server->ensureDatabasesSpareIt())->toThrow(ValidationException::class, 'is busy with Upgrade');
});

it('refuses a member of a database that is migrating data in', function () {
    $database = migratingDatabase('mysql');
    migrationOf($database, MigrationMode::Continuous);

    expect(fn () => $database->seed()->server->ensureDatabasesSpareIt())->toThrow(ValidationException::class, 'is migrating data in');
});
