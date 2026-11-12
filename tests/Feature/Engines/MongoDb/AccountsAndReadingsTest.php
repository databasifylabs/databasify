<?php

declare(strict_types=1);

use App\Engines\Data\Grant;
use App\Engines\EngineClient;
use App\Engines\Requests\AccountList;
use App\Engines\Requests\DatabaseCreate;
use App\Engines\Requests\DatabaseDrop;
use App\Engines\Requests\NodeMetrics;
use App\Engines\Requests\NodeStatus;
use App\Engines\Requests\UserCreate;
use App\Engines\Requests\UserDrop;
use App\Engines\Requests\UserGrant;
use App\Engines\Requests\UserSetPassword;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    config(['databasify.php_engines' => ['mongodb']]);
    $this->scripts = fakeScripts();
    $this->node = idleDatabase('mongodb', 1, versionLine: '8.0')->nodes()->sole();
});

function sendToMongo(object $node, object $request): object
{
    return resolve(EngineClient::class)->send($node, $request);
}

it('creates a user with every value only in the env', function () {
    $this->scripts->respond('mongodb/accounts/create-user', ['created' => true]);
    $password = "p'w\"\\\$`x";

    $reply = sendToMongo($this->node, new UserCreate("o'b", $password, [new Grant('shop', GrantAccess::Write), new Grant('app', GrantAccess::Read)], null));

    expect($reply->created)->toBeTrue();
    $this->scripts->assertRan('mongodb/accounts/create-user', fn (string $script, array $env): bool => $env['NAME'] === "o'b"
        && $env['PASSWORD'] === $password
        && $env['ENCRYPTION'] === 'SCRAM-SHA-256'
        && json_decode($env['GRANTS'], true) === [['database' => 'shop', 'access' => 'write'], ['database' => 'app', 'access' => 'read']]
        && ! str_contains($script, $password)
        && str_contains($script, 'createUser: env.NAME')
        && str_contains($script, 'updateUser: env.NAME')
        && str_contains($script, "'database ' + JSON.stringify(grant.database) + ' does not exist'"));
});

it('refuses what the Go engine refuses before running anything', function (object $request, string $message) {
    expect(fn () => sendToMongo($this->node, $request))->toThrow(InvalidEngineRequest::class, $message);
    expect($this->scripts->ran())->toBe([]);
})->with([
    'reserved user' => [new UserDrop('databasify_pbm'), 'databasify_pbm is reserved'],
    'reserved database' => [new DatabaseCreate('config'), 'config is reserved'],
    'reserved grant' => [new UserGrant('app', [new Grant('admin', GrantAccess::Read)]), 'admin is reserved'],
    'unknown encryption' => [new UserCreate('app', 'pw', [], 'MONGODB-X509'), 'password encryption "MONGODB-X509" is not offered'],
    'control character' => [new UserSetPassword('app', "line\nbreak", null), 'a SCRAM-SHA-256 password cannot contain control characters such as a newline or tab'],
]);

it('allows a control character with SCRAM-SHA-1, as Go does', function () {
    sendToMongo($this->node, new UserSetPassword('app', "tab\there", 'SCRAM-SHA-1'));

    $this->scripts->assertRan('mongodb/accounts/set-password', fn (string $script, array $env): bool => $env['ENCRYPTION'] === 'SCRAM-SHA-1' && str_contains($script, "'user ' + JSON.stringify(env.NAME) + ' does not exist'"));
});

it('runs the other account commands with their Go behaviour', function () {
    sendToMongo($this->node, new DatabaseCreate('shop'));
    sendToMongo($this->node, new DatabaseDrop('shop'));
    sendToMongo($this->node, new UserGrant('app', [new Grant('shop', GrantAccess::Read)], true));
    sendToMongo($this->node, new UserDrop('app'));

    $this->scripts->assertRan('mongodb/accounts/create-database', fn (string $script): bool => str_contains($script, "create: '_databasify'") && str_contains($script, 'hasCode(error, 48)'))
        ->assertRan('mongodb/accounts/drop-database', fn (string $script): bool => str_contains($script, 'revokeRolesFromUser') && str_contains($script, 'dropDatabase: 1'))
        ->assertRan('mongodb/accounts/grant', fn (string $script, array $env): bool => $env['REPLICATION'] === 'true' && str_contains($script, "role: 'clusterMonitor'"))
        ->assertRan('mongodb/accounts/drop-user', fn (string $script): bool => str_contains($script, 'hasCode(error, 11)'));
});

it('lists accounts without the reserved ones', function () {
    $this->scripts->respond('mongodb/accounts/list', ['users' => ['root', 'zed', 'app'], 'databases' => ['shop', 'admin', 'app']]);

    $accounts = sendToMongo($this->node, new AccountList);

    expect($accounts->users)->toBe(['app', 'zed'])->and($accounts->databases)->toBe(['app', 'shop']);
});

it('reads status and metrics', function () {
    $this->scripts->respond('mongodb/nodes/status', ['role' => 'replica', 'version' => '8.0.4'])
        ->respond('mongodb/nodes/metrics', ['connections' => 3, 'queries' => 99, 'dataMb' => 12]);

    $status = sendToMongo($this->node, new NodeStatus);
    $metrics = sendToMongo($this->node, new NodeMetrics);

    expect($status->role)->toBe(NodeRole::Replica)->and($status->version)->toBe('8.0.4')
        ->and($metrics->queries)->toBe(99);
    $this->scripts->assertRan('mongodb/nodes/status', fn (string $script): bool => str_contains($script, 'mongo_try none') && str_contains($script, 'mongo_js member'))
        ->assertRan('mongodb/nodes/metrics', fn (string $script): bool => str_contains($script, 'opcounters') && str_contains($script, 'totalSize'));
});
