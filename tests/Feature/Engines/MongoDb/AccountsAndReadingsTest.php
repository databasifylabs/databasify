<?php

declare(strict_types=1);

use App\Engines\Contracts\Accounts;
use App\Engines\Data\Grant;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    $this->scripts = fakeScripts();
    $this->node = idleDatabase('mongodb', 1, versionLine: '8.0')->nodes()->sole();
});

it('creates a user with every value only in the env', function () {
    $password = "p'w\"\\\$`x";

    $this->node->driver()->accounts()->createUser("o'b", $password, [new Grant('shop', GrantAccess::Write), new Grant('app', GrantAccess::Read)], null);

    $this->scripts->assertRan('mongodb/accounts/create-user', fn (string $script, array $env): bool => $env['NAME'] === "o'b"
        && $env['PASSWORD'] === $password
        && $env['ENCRYPTION'] === 'SCRAM-SHA-256'
        && json_decode($env['GRANTS'], true) === [['database' => 'shop', 'access' => 'write'], ['database' => 'app', 'access' => 'read']]
        && ! str_contains($script, $password)
        && str_contains($script, 'createUser: env.NAME')
        && str_contains($script, 'updateUser: env.NAME')
        && str_contains($script, "'database ' + JSON.stringify(grant.database) + ' does not exist'"));
});

it('refuses what the Go engine refuses before running anything', function (Closure $call, string $message) {
    expect(fn () => $call($this->node->driver()->accounts()))->toThrow(InvalidEngineRequest::class, $message);
    expect($this->scripts->ran())->toBe([]);
})->with([
    'reserved user' => [fn (Accounts $accounts) => $accounts->dropUser('databasify_pbm'), 'databasify_pbm is reserved'],
    'reserved database' => [fn (Accounts $accounts) => $accounts->createDatabase('config'), 'config is reserved'],
    'reserved grant' => [fn (Accounts $accounts) => $accounts->grant('app', [new Grant('admin', GrantAccess::Read)], false), 'admin is reserved'],
    'unknown encryption' => [fn (Accounts $accounts) => $accounts->createUser('app', 'pw', [], 'MONGODB-X509'), 'password encryption "MONGODB-X509" is not offered'],
    'control character' => [fn (Accounts $accounts) => $accounts->setPassword('app', "line\nbreak", null), 'a SCRAM-SHA-256 password cannot contain control characters such as a newline or tab'],
]);

it('allows a control character with SCRAM-SHA-1, as Go does', function () {
    $this->node->driver()->accounts()->setPassword('app', "tab\there", 'SCRAM-SHA-1');

    $this->scripts->assertRan('mongodb/accounts/set-password', fn (string $script, array $env): bool => $env['ENCRYPTION'] === 'SCRAM-SHA-1' && str_contains($script, "'user ' + JSON.stringify(env.NAME) + ' does not exist'"));
});

it('runs the other account commands with their Go behaviour', function () {
    $this->node->driver()->accounts()->createDatabase('shop');
    $this->node->driver()->accounts()->dropDatabase('shop');
    $this->node->driver()->accounts()->grant('app', [new Grant('shop', GrantAccess::Read)], true);
    $this->node->driver()->accounts()->dropUser('app');

    $this->scripts->assertRan('mongodb/accounts/create-database', fn (string $script): bool => str_contains($script, "create: '_databasify'") && str_contains($script, 'hasCode(error, 48)'))
        ->assertRan('mongodb/accounts/drop-database', fn (string $script): bool => str_contains($script, 'revokeRolesFromUser') && str_contains($script, 'dropDatabase: 1'))
        ->assertRan('mongodb/accounts/grant', fn (string $script, array $env): bool => $env['REPLICATION'] === 'true' && str_contains($script, "role: 'clusterMonitor'"))
        ->assertRan('mongodb/accounts/drop-user', fn (string $script): bool => str_contains($script, 'hasCode(error, 11)'));
});

it('lists accounts without the reserved ones', function () {
    $this->scripts->respond('mongodb/accounts/list', ['users' => ['root', 'zed', 'app'], 'databases' => ['shop', 'admin', 'app']]);

    $accounts = $this->node->driver()->accounts()->list();

    expect($accounts->users)->toBe(['app', 'zed'])->and($accounts->databases)->toBe(['app', 'shop']);
});

it('reads status and metrics', function () {
    $this->scripts->respond('mongodb/nodes/status', ['role' => 'replica', 'version' => '8.0.4'])
        ->respond('mongodb/nodes/metrics', ['connections' => 3, 'queries' => 99, 'dataMb' => 12]);

    $status = $this->node->driver()->nodes()->status();
    $metrics = $this->node->driver()->nodes()->metrics();

    expect($status->role)->toBe(NodeRole::Replica)->and($status->version)->toBe('8.0.4')
        ->and($metrics->queries)->toBe(99);
    $this->scripts->assertRan('mongodb/nodes/status', fn (string $script): bool => str_contains($script, 'mongo_try none') && str_contains($script, 'mongo_js member'))
        ->assertRan('mongodb/nodes/metrics', fn (string $script): bool => str_contains($script, 'opcounters') && str_contains($script, 'totalSize'));
});
