<?php

declare(strict_types=1);

use App\Engines\Data\Grant;
use App\Engines\EngineClient;
use App\Engines\Requests\AccountList;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\DatabaseDrop;
use App\Engines\Requests\UserCreate;
use App\Engines\Requests\UserDrop;
use App\Engines\Requests\UserGrant;
use App\Engines\Requests\UserSetPassword;
use App\Enums\Databases\GrantAccess;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    config(['databasify.php_engines' => ['postgres']]);
    $this->scripts = fakeScripts();
    $this->node = engineNode();
});

function sendToPostgres(object $node, object $request): object
{
    return resolve(EngineClient::class)->send($node, $request);
}

it('creates a user with its name and password only in the env', function () {
    $name = "o'b\"x \$HOME";
    $password = "p'w\n--; DROP";
    $this->scripts->respond('postgres/accounts/create-user', ['created' => true]);

    $reply = sendToPostgres($this->node, new UserCreate($name, $password, [new Grant('shop', GrantAccess::Write)], null));

    expect($reply->created)->toBeTrue();
    $this->scripts->assertRan('postgres/accounts/create-user', fn (string $script, array $env): bool => $env['NAME'] === $name
        && $env['PASSWORD'] === $password
        && json_decode($env['GRANTS'], true) === [['database' => 'shop', 'access' => 'write']]
        && json_decode($env['RESERVED_DATABASES'], true) === ['postgres', 'template0', 'template1']
        && ! str_contains($script, $password)
        && str_contains($script, 'setpriv --reuid=64999 --regid=64999 --clear-groups psql')
        && str_contains($script, 'CREATE ROLE :"name" LOGIN;')
        && str_contains($script, 'pg_failed'));
});

it('refuses reserved names and encryption choices before running anything', function (object $request, string $message) {
    expect(fn () => sendToPostgres($this->node, $request))->toThrow(InvalidEngineRequest::class, $message);

    expect($this->scripts->ran())->toBe([]);
})->with([
    'reserved user' => [fn () => new UserCreate('replicator', 'pw', [], null), 'replicator is reserved'],
    'pg_ role' => [fn () => new UserGrant('pg_monitor', []), 'pg_monitor is reserved'],
    'reserved database grant' => [fn () => new UserCreate('app', 'pw', [new Grant('template1', GrantAccess::Read)], null), 'template1 is reserved'],
    'reserved database' => [fn () => new DatabaseDrop('postgres'), 'postgres is reserved'],
    'encryption' => [fn () => new UserSetPassword('app', 'pw', 'scram-sha-256'), 'PostgreSQL has no password encryption choice'],
]);

it('lists accounts without the reserved ones, sorted', function () {
    $this->scripts->respond('postgres/accounts/list', ['users' => ['zed', 'postgres', 'app', 'replicator'], 'databases' => ['shop', 'postgres', 'app']]);

    $reply = sendToPostgres($this->node, new AccountList);

    expect($reply->users)->toBe(['app', 'zed'])
        ->and($reply->databases)->toBe(['app', 'shop']);
});

it('creates and drops a database by name', function () {
    sendToPostgres($this->node, new DatabaseCreate('shop'));
    sendToPostgres($this->node, new DatabaseDrop('shop'));

    $this->scripts->assertRan('postgres/accounts/create-database', fn (string $script, array $env): bool => $env === ['NAME' => 'shop'] && str_contains($script, 'CREATE DATABASE :"name";'))
        ->assertRan('postgres/accounts/drop-database', fn (string $script, array $env): bool => $env === ['NAME' => 'shop'] && str_contains($script, 'WITH (FORCE)'));
});

it('sets a password, grants with replication and drops a user', function () {
    sendToPostgres($this->node, new UserSetPassword('app', 'n3w', null));
    sendToPostgres($this->node, new UserGrant('app', [new Grant('shop', GrantAccess::Read)], replication: true));
    sendToPostgres($this->node, new UserDrop('app'));

    $this->scripts->assertRan('postgres/accounts/set-password', fn (string $script, array $env): bool => $env === ['NAME' => 'app', 'PASSWORD' => 'n3w'])
        ->assertRan('postgres/accounts/grant', fn (string $script, array $env): bool => $env['REPLICATION'] === 'true' && str_contains($script, 'ALTER ROLE :"name" REPLICATION'))
        ->assertRan('postgres/accounts/drop-user', fn (string $script, array $env): bool => $env === ['NAME' => 'app'] && str_contains($script, 'REASSIGN OWNED BY'));
});
