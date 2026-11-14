<?php

declare(strict_types=1);

use App\Engines\Contracts\Accounts;
use App\Engines\Data\Grant;
use App\Enums\Databases\GrantAccess;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    $this->scripts = fakeScripts();
    $this->node = engineNode();
});

it('creates a user with its name and password only in the env', function () {
    $name = "o'b\"x \$HOME";
    $password = "p'w\n--; DROP";

    $this->node->driver()->accounts()->createUser($name, $password, [new Grant('shop', GrantAccess::Write)], null);

    $this->scripts->assertRan('postgres/accounts/create-user', fn (string $script, array $env): bool => $env['NAME'] === $name
        && $env['PASSWORD'] === $password
        && json_decode($env['GRANTS'], true) === [['database' => 'shop', 'access' => 'write']]
        && json_decode($env['RESERVED_DATABASES'], true) === ['postgres', 'template0', 'template1']
        && ! str_contains($script, $password)
        && str_contains($script, 'setpriv --reuid=64999 --regid=64999 --clear-groups psql')
        && str_contains($script, 'CREATE ROLE :"name" LOGIN;')
        && str_contains($script, 'pg_failed'));
});

it('refuses reserved names and encryption choices before running anything', function (Closure $call, string $message) {
    expect(fn () => $call($this->node->driver()->accounts()))->toThrow(InvalidEngineRequest::class, $message);

    expect($this->scripts->ran())->toBe([]);
})->with([
    'reserved user' => [fn (Accounts $accounts) => $accounts->createUser('replicator', 'pw', [], null), 'replicator is reserved'],
    'pg_ role' => [fn (Accounts $accounts) => $accounts->grant('pg_monitor', [], false), 'pg_monitor is reserved'],
    'reserved database grant' => [fn (Accounts $accounts) => $accounts->createUser('app', 'pw', [new Grant('template1', GrantAccess::Read)], null), 'template1 is reserved'],
    'reserved database' => [fn (Accounts $accounts) => $accounts->dropDatabase('postgres'), 'postgres is reserved'],
    'encryption' => [fn (Accounts $accounts) => $accounts->setPassword('app', 'pw', 'scram-sha-256'), 'PostgreSQL has no password encryption choice'],
]);

it('lists accounts without the reserved ones, sorted', function () {
    $this->scripts->respond('postgres/accounts/list', ['users' => ['zed', 'postgres', 'app', 'replicator'], 'databases' => ['shop', 'postgres', 'app']]);

    $reply = $this->node->driver()->accounts()->list();

    expect($reply->users)->toBe(['app', 'zed'])
        ->and($reply->databases)->toBe(['app', 'shop']);
});

it('creates and drops a database by name', function () {
    $this->node->driver()->accounts()->createDatabase('shop');
    $this->node->driver()->accounts()->dropDatabase('shop');

    $this->scripts->assertRan('postgres/accounts/create-database', fn (string $script, array $env): bool => $env === ['NAME' => 'shop'] && str_contains($script, 'CREATE DATABASE :"name";'))
        ->assertRan('postgres/accounts/drop-database', fn (string $script, array $env): bool => $env === ['NAME' => 'shop'] && str_contains($script, 'WITH (FORCE)'));
});

it('sets a password, grants with replication and drops a user', function () {
    $this->node->driver()->accounts()->setPassword('app', 'n3w', null);
    $this->node->driver()->accounts()->grant('app', [new Grant('shop', GrantAccess::Read)], replication: true);
    $this->node->driver()->accounts()->dropUser('app');

    $this->scripts->assertRan('postgres/accounts/set-password', fn (string $script, array $env): bool => $env === ['NAME' => 'app', 'PASSWORD' => 'n3w'])
        ->assertRan('postgres/accounts/grant', fn (string $script, array $env): bool => $env['REPLICATION'] === 'true' && str_contains($script, 'ALTER ROLE :"name" REPLICATION'))
        ->assertRan('postgres/accounts/drop-user', fn (string $script, array $env): bool => $env === ['NAME' => 'app'] && str_contains($script, 'REASSIGN OWNED BY'));
});
