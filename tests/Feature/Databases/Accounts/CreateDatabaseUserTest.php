<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\CreateDatabaseUser;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\PasswordEncryption;
use App\Enums\Servers\ServerLogStatus;
use Illuminate\Validation\ValidationException;

it('creates mysql users on the primary before recording them', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $ssh = fakeSsh();

    $user = resolve(CreateDatabaseUser::class)->handle($database, 'reporting');

    expect(sqlSentIn($ssh->scriptAt(0)))
        ->toContain("CREATE USER IF NOT EXISTS 'reporting'@'%' IDENTIFIED WITH caching_sha2_password BY '{$user->password}';")
        ->toContain("GRANT ALL PRIVILEGES ON `defaultdb`.* TO 'reporting'@'%'")
        ->and($ssh->scriptAt(0))->not->toContain($user->password)
        ->and($database->users()->pluck('name')->all())->toBe(['databasify', 'reporting'])
        ->and($user->fresh()->password_encryption)->toBe(PasswordEncryption::Default);
});

it('creates mysql users with the legacy password encryption when chosen', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $ssh = fakeSsh();

    $user = resolve(CreateDatabaseUser::class)->handle($database, 'reporting', PasswordEncryption::Legacy);

    expect(sqlSentIn($ssh->scriptAt(0)))->toContain("CREATE USER IF NOT EXISTS 'reporting'@'%' IDENTIFIED WITH mysql_native_password BY '{$user->password}';")
        ->and($user->fresh()->password_encryption)->toBe(PasswordEncryption::Legacy);
});

it('ignores a password encryption on engines without one', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $ssh = fakeSsh();

    $user = resolve(CreateDatabaseUser::class)->handle($database, 'reporting', PasswordEncryption::Legacy);

    expect(sqlSentIn($ssh->scriptAt(0)))->toStartWith("CREATE USER IF NOT EXISTS 'reporting'@'%' IDENTIFIED BY ")
        ->and($user->fresh()->password_encryption)->toBeNull();
});

it('creates mariadb users with the mysql dialect through the mariadb client', function () {
    $database = idleDatabase('mariadb', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $ssh = fakeSsh();

    $user = resolve(CreateDatabaseUser::class)->handle($database, 'reporting');

    expect(sqlSentIn($ssh->scriptAt(0)))->toBe("CREATE USER IF NOT EXISTS 'reporting'@'%' IDENTIFIED BY '{$user->password}';\nGRANT ALL PRIVILEGES ON `defaultdb`.* TO 'reporting'@'%';\n")
        ->and($database->users()->pluck('name')->all())->toBe(['databasify', 'reporting']);
});

it('creates postgres login roles and grants them every database', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $ssh = fakeSsh();

    $user = resolve(CreateDatabaseUser::class)->handle($database, 'reporting');

    $sql = "SELECT 'CREATE ROLE \"reporting\" LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'reporting') \\gexec\n"
        ."ALTER ROLE \"reporting\" LOGIN PASSWORD '{$user->password}';\n"
        ."\\connect postgres\nGRANT ALL PRIVILEGES ON DATABASE \"defaultdb\" TO \"reporting\";\n\\connect \"defaultdb\"\nGRANT ALL ON SCHEMA public TO \"reporting\";\n";
    expect($ssh->scriptAt(0))->toContain("echo '".base64_encode($sql)."' | pg_from")->not->toContain($user->password)
        ->and($database->users()->pluck('name')->all())->toBe(['databasify', 'reporting']);
});

it('records no user when the sql fails on the primary', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    fakeSsh()->respond('ERROR 1396', 1);

    expect(fn () => resolve(CreateDatabaseUser::class)->handle($database, 'reporting'))->toThrow(ValidationException::class, 'Create user reporting failed')
        ->and($database->users()->pluck('name')->all())->toBe(['databasify'])
        ->and($database->serverLogs()->sole()->status)->toBe(ServerLogStatus::Failed);
});

it('refuses users on sentinel databases', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $ssh = fakeSsh();

    expect(fn () => resolve(CreateDatabaseUser::class)->handle($database, 'reporting'))->toThrow(ValidationException::class, 'have no users or databases to manage')
        ->and($ssh->runs)->toBe([])
        ->and($database->users()->count())->toBe(0);
})->with(['valkey', 'redis']);
