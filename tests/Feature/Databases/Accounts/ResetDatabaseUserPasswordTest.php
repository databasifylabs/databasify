<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\ResetDatabaseUserPassword;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\PasswordEncryption;
use Illuminate\Validation\ValidationException;

it('sets a fresh mysql password on the primary and records it', function (string $engine, ?PasswordEncryption $encryption, string $identified) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'old-secret', 'password_encryption' => $encryption]);
    $ssh = fakeSsh();

    $password = resolve(ResetDatabaseUserPassword::class)->handle($user)->fresh()->password;

    expect($password)->not->toBe('old-secret')->toHaveLength(32)
        ->and(sqlSentIn($ssh->scriptAt(0)))->toBe("ALTER USER 'reporting'@'%' {$identified} '{$password}';\n")
        ->and($ssh->scriptAt(0))->not->toContain($password)
        ->and($database->serverLogs()->sole()->name)->toBe('Reset password for reporting');
})->with([
    'mysql keeps the default encryption' => ['mysql', PasswordEncryption::Default, 'IDENTIFIED WITH caching_sha2_password BY'],
    'mysql keeps the legacy encryption' => ['mysql', PasswordEncryption::Legacy, 'IDENTIFIED WITH mysql_native_password BY'],
    'mariadb' => ['mariadb', null, 'IDENTIFIED BY'],
]);

it('sets a fresh postgres role password', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'old-secret']);
    $ssh = fakeSsh();

    $password = resolve(ResetDatabaseUserPassword::class)->handle($user)->fresh()->password;

    expect($ssh->scriptAt(0))->toContain("echo '".base64_encode("ALTER ROLE \"reporting\" PASSWORD '{$password}';\n")."' | pg_from");
});

it('keeps the old password when the change fails', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'old-secret']);
    fakeSsh()->respond('ERROR', 1);

    expect(fn () => resolve(ResetDatabaseUserPassword::class)->handle($user))->toThrow(ValidationException::class, 'Reset password for reporting failed')
        ->and($user->fresh()->password)->toBe('old-secret');
});
