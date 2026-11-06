<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\DeleteDatabaseUser;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use Illuminate\Validation\ValidationException;

it('drops mysql users on the primary before forgetting them', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'secret']);
    $ssh = fakeSsh();

    resolve(DeleteDatabaseUser::class)->handle($user);

    expect(sqlSentIn($ssh->scriptAt(0)))->toBe("DROP USER IF EXISTS 'reporting'@'%';\n")
        ->and($database->users()->pluck('name')->all())->toBe(['databasify'])
        ->and($database->serverLogs()->sole()->name)->toBe('Delete user reporting');
})->with(['mysql', 'mariadb']);

it('hands postgres objects owned by the role to postgres in every database before dropping it', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $database->schemas()->create(['name' => 'analytics']);
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'secret']);
    $ssh = fakeSsh();

    resolve(DeleteDatabaseUser::class)->handle($user);

    $sql = "\\connect \"analytics\"\nREASSIGN OWNED BY \"reporting\" TO postgres;\nDROP OWNED BY \"reporting\";\n"
        ."\\connect \"defaultdb\"\nREASSIGN OWNED BY \"reporting\" TO postgres;\nDROP OWNED BY \"reporting\";\n"
        ."\\connect postgres\nDROP ROLE IF EXISTS \"reporting\";\n";
    expect($ssh->scriptAt(0))->toContain("echo '".base64_encode($sql)."' | pg_from")
        ->and($database->users()->pluck('name')->all())->toBe(['databasify']);
});

it('keeps the user when the drop fails', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $user = $database->users()->create(['name' => 'reporting', 'password' => 'secret']);
    fakeSsh()->respond('ERROR', 1);

    expect(fn () => resolve(DeleteDatabaseUser::class)->handle($user))->toThrow(ValidationException::class, 'Delete user reporting failed')
        ->and($database->users()->pluck('name')->all())->toBe(['databasify', 'reporting']);
});

it('refuses to delete the default user', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $ssh = fakeSsh();

    expect(fn () => resolve(DeleteDatabaseUser::class)->handle($database->users()->sole()))->toThrow(ValidationException::class, "databasify is the default user and can't be deleted.")
        ->and($ssh->runs)->toBe([])
        ->and($database->users()->pluck('name')->all())->toBe(['databasify']);
})->with(['mysql', 'mariadb', 'postgres', 'mongodb']);
