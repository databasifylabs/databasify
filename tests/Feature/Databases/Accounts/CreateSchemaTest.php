<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\CreateDatabaseUser;
use App\Actions\Databases\Accounts\CreateSchema;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use Illuminate\Validation\ValidationException;

it('grants new databases to every user and records nothing when the sql fails', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $ssh = fakeSsh()->respond()->respond('ERROR 1007: database exists', 1);

    resolve(CreateSchema::class)->handle($database, 'analytics');

    expect(sqlSentIn($ssh->scriptAt(0)))->toBe("CREATE DATABASE IF NOT EXISTS `analytics`;\nGRANT ALL PRIVILEGES ON `analytics`.* TO 'databasify'@'%';\n")
        ->and(fn () => resolve(CreateSchema::class)->handle($database, 'broken'))->toThrow(ValidationException::class, 'Create database broken failed')
        ->and(fn () => resolve(CreateSchema::class)->handle($database, 'analytics'))->toThrow(ValidationException::class, 'already has a database')
        ->and(fn () => resolve(CreateDatabaseUser::class)->handle($database, 'databasify'))->toThrow(ValidationException::class, 'already has a user')
        ->and($database->schemas()->pluck('name')->all())->toBe(['analytics', 'defaultdb']);
});

it('creates postgres databases and grants the public schema to every user', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $ssh = fakeSsh();

    resolve(CreateSchema::class)->handle($database, 'analytics');

    $sql = "SELECT 'CREATE DATABASE \"analytics\"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'analytics') \\gexec\n"
        ."\\connect postgres\nGRANT ALL PRIVILEGES ON DATABASE \"analytics\" TO \"databasify\";\n\\connect \"analytics\"\nGRANT ALL ON SCHEMA public TO \"databasify\";\n";
    expect($ssh->scriptAt(0))->toContain("echo '".base64_encode($sql)."' | pg_from")
        ->and($database->schemas()->pluck('name')->all())->toBe(['analytics', 'defaultdb']);
});

it('refuses databases on sentinel databases', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $ssh = fakeSsh();

    expect(fn () => resolve(CreateSchema::class)->handle($database, 'analytics'))->toThrow(ValidationException::class, 'have no users or databases to manage')
        ->and($ssh->runs)->toBe([])
        ->and($database->schemas()->count())->toBe(0);
})->with(['valkey', 'redis']);
