<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\CreateSchema;
use App\Actions\Databases\Accounts\GrantMigrationSourceRoles;

beforeEach(function () {
    $this->scripts = fakeScripts();
});

it('lets the default user of an existing mongodb database serve as a migration source', function () {
    $database = idleDatabase('mongodb', 1, name: 'docs');

    resolve(GrantMigrationSourceRoles::class)->handle($database);

    $this->scripts->assertRan('engines.mongodb.accounts.grant-source-roles', fn (string $script, array $env): bool => $env['NAME'] === $database->settings->defaultUsername());
});

it('logs a failed grant and lets the rollout finish', function () {
    $database = idleDatabase('mongodb', 1, name: 'docs');
    $this->scripts->respond('engines.mongodb.accounts.grant-source-roles', exit: 1);

    resolve(GrantMigrationSourceRoles::class)->handle($database);

    expect($database->primaryServer()->serverLogs()->where('name', 'Grant migration source roles to the default user')->sole()->output)->toContain('The next upgrade tries again');
});

it('leaves engines without source roles alone', function () {
    resolve(GrantMigrationSourceRoles::class)->handle(idleDatabase('postgres', 1, name: 'shop'));

    expect($this->scripts->ran())->toBe([]);
});

it('keeps the source roles of the default user when a database is created on the source', function () {
    $database = idleDatabase('mongodb', 1, name: 'docs');

    resolve(CreateSchema::class)->handle($database, 'e2e_migrated');

    $this->scripts->assertRan('engines.mongodb.accounts.grant', fn (string $script, array $env): bool => $env['NAME'] === $database->settings->defaultUsername() && $env['REPLICATION'] === 'true' && str_contains($env['GRANTS'], 'e2e_migrated'));
});
