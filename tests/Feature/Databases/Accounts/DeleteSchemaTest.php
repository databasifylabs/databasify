<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\DeleteSchema;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use Illuminate\Validation\ValidationException;

it('drops mysql databases on the primary before forgetting them', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $schema = $database->schemas()->create(['name' => 'analytics']);
    $ssh = fakeSsh();

    resolve(DeleteSchema::class)->handle($schema);

    expect(sqlSentIn($ssh->scriptAt(0)))->toBe("DROP DATABASE IF EXISTS `analytics`;\n")
        ->and($database->schemas()->pluck('name')->all())->toBe(['defaultdb'])
        ->and($database->serverLogs()->sole()->name)->toBe('Delete database analytics');
})->with(['mysql', 'mariadb']);

it('force drops postgres databases', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $schema = $database->schemas()->create(['name' => 'analytics']);
    $ssh = fakeSsh();

    resolve(DeleteSchema::class)->handle($schema);

    expect($ssh->scriptAt(0))->toContain("echo '".base64_encode("DROP DATABASE IF EXISTS \"analytics\" WITH (FORCE);\n")."' | pg_from")
        ->and($database->schemas()->pluck('name')->all())->toBe(['defaultdb']);
});

it('keeps the database when the drop fails', function () {
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $database->seed()->update(['role' => NodeRole::Primary]);
    $schema = $database->schemas()->create(['name' => 'analytics']);
    fakeSsh()->respond('ERROR', 1);

    expect(fn () => resolve(DeleteSchema::class)->handle($schema))->toThrow(ValidationException::class, 'Delete database analytics failed')
        ->and($database->schemas()->pluck('name')->all())->toBe(['analytics', 'defaultdb']);
});

it('refuses to delete the default database', function (string $engine) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $ssh = fakeSsh();

    expect(fn () => resolve(DeleteSchema::class)->handle($database->schemas()->sole()))->toThrow(ValidationException::class, "defaultdb is the default database and can't be deleted.")
        ->and($ssh->runs)->toBe([])
        ->and($database->schemas()->pluck('name')->all())->toBe(['defaultdb']);
})->with(['mysql', 'mariadb', 'postgres', 'mongodb']);
