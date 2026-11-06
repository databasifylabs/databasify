<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\ChangePasswordEncryption;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\PasswordEncryption;
use Illuminate\Validation\ValidationException;

it('switches a mysql user to the legacy encryption with the same password', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $user = $database->users()->sole();
    $ssh = fakeSsh();

    resolve(ChangePasswordEncryption::class)->handle($user, PasswordEncryption::Legacy);

    expect(sqlSentIn($ssh->scriptAt(0)))->toBe("ALTER USER 'databasify'@'%' IDENTIFIED WITH mysql_native_password BY '{$user->password}';\n")
        ->and($ssh->scriptAt(0))->not->toContain($user->password)
        ->and($user->fresh()->password_encryption)->toBe(PasswordEncryption::Legacy)
        ->and($database->serverLogs()->sole()->name)->toBe('Change password encryption for databasify');
});

it('runs nothing when the user already has that encryption', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $ssh = fakeSsh();

    resolve(ChangePasswordEncryption::class)->handle($database->users()->sole(), PasswordEncryption::Default);

    expect($ssh->runs)->toBe([]);
});

it('keeps the encryption when the change fails', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $user = $database->users()->sole();
    fakeSsh()->respond('ERROR 1396', 1);

    expect(fn () => resolve(ChangePasswordEncryption::class)->handle($user, PasswordEncryption::Legacy))->toThrow(ValidationException::class)
        ->and($user->fresh()->password_encryption)->toBe(PasswordEncryption::Default);
});

it('refuses engines without a password encryption choice', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $ssh = fakeSsh();

    expect(fn () => resolve(ChangePasswordEncryption::class)->handle($database->users()->sole(), PasswordEncryption::Legacy))->toThrow(ValidationException::class, 'PostgreSQL has no password encryption to choose.')
        ->and($ssh->runs)->toBe([]);
});
