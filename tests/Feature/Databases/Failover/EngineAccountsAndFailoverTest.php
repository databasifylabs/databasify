<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\ChangePasswordEncryption;
use App\Actions\Databases\Accounts\CreateDatabaseUser;
use App\Actions\Databases\Accounts\CreateSchema;
use App\Actions\Databases\Accounts\DeleteDatabaseUser;
use App\Actions\Databases\Accounts\DeleteSchema;
use App\Actions\Databases\Accounts\ResetDatabaseUserPassword;
use App\Actions\Databases\Failover\PromoteStandby;
use App\Actions\Databases\Failover\RecoverDatabase;
use App\Actions\Databases\Pools\ApplyConnectionPools;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\PoolMode;
use App\Exceptions\Engines\InvalidEngineRequest;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeServerConnection;

function payloadsOf(FakeServerConnection $ssh, string $command): array
{
    return collect($ssh->runs)->keys()
        ->filter(fn (int $index): bool => str_contains($ssh->scriptAt($index), "| databasify {$command} "))
        ->map(fn (int $index): array => ['server' => $ssh->runs[$index]['server'], 'request' => json_decode($ssh->engineRequestAt($index), true)])
        ->values()
        ->all();
}

it('creates a user with grants on the primary', function () {
    $ssh = fakeEngine()->respondToEngine('user create', ['created' => true]);
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Replica, NodeRole::Primary);
    $primary = $database->nodes()->orderBy('position')->get()[1]->server;

    resolve(CreateDatabaseUser::class)->handle($database, 'reporting');

    $creates = payloadsOf($ssh, 'user create');
    expect($creates)->toHaveCount(1)
        ->and($creates[0]['server'])->toBe($primary->name)
        ->and($creates[0]['request'])->name->toBe('reporting')->grants->toBe([['database' => $database->settings->defaultSchema(), 'access' => 'write']]);
});

it('retries on the new primary after not_primary', function () {
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    [$former, $current] = $database->nodes()->orderBy('position')->get()->map->server->all();
    $ssh = fakeSsh()->respondWhen(fn (string $server, string $script): bool => $server === $former->name && str_contains($script, '| databasify user create '), json_encode(['error' => ['code' => 'not_primary', 'message' => 'run it on the primary', 'details' => (object) []]]), 1);
    fakeEngine(answerNodeStatus(answerNodeStatus($ssh, $former, 'replica'), $current, 'primary'))->respondToEngine('user create', ['created' => true]);

    resolve(CreateDatabaseUser::class)->handle($database, 'reporting');

    expect(array_column(payloadsOf($ssh, 'user create'), 'server'))->toBe([$former->name, $current->name])
        ->and($database->users()->pluck('name'))->toContain('reporting');
});

it('applies a pool on every node', function () {
    $ssh = fakeEngine(fakeSsh()->respondToEngine('pool list', ['pools' => [['name' => 'old_pool', 'database' => 'defaultdb', 'size' => 5, 'mode' => 'session']]]));
    $database = idleDatabase('postgres', 2);
    $database->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'defaultdb', 'size' => 20, 'mode' => PoolMode::Transaction]);

    resolve(ApplyConnectionPools::class)->handle($database);

    expect(collect(payloadsOf($ssh, 'pool create'))->pluck('server')->sort()->values()->all())->toBe($database->nodes->map->server->pluck('name')->sort()->values()->all())
        ->and(payloadsOf($ssh, 'pool create')[0]['request'])->toBe(['name' => 'shop_pool', 'database' => 'defaultdb', 'size' => 20, 'mode' => 'transaction'])
        ->and(payloadsOf($ssh, 'pool delete')[0]['request'])->toBe(['name' => 'old_pool']);
});

it('switches the primary with cluster failover', function () {
    $database = idleDatabase('mysql', 2);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    $ssh = fakeEngine(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $standby->server, 'replica'));

    resolve(PromoteStandby::class)->handle($database);

    expect(payloadsOf($ssh, 'cluster failover'))->toBe([['server' => $primary->server->name, 'request' => ['target' => $standby->privateIp()]]]);
});

it('never forces quorum without the owner confirmation', function () {
    $database = idleDatabase('mysql', 3);
    [$alive, $down, $gone] = $database->nodes()->orderBy('position')->get()->map->server->all();
    $ssh = fakeEngine(answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $alive, 'offline'), $down, null), $gone, null))
        ->respondToEngine('cluster quorum', ['total' => 3, 'online' => 1])->respondToEngine('node wake', [])->respondToEngine('cluster position', ['position' => 10])->respondToEngine('cluster force-quorum', ['leader' => 'x']);

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'confirm they are down')
        ->and(payloadsOf($ssh, 'cluster force-quorum'))->toBe([]);

    resolve(RecoverDatabase::class)->handle($database->fresh(), othersAreDown: true);
    advanceFlows();

    expect(payloadsOf($ssh, 'cluster force-quorum'))->toBe([['server' => $alive->name, 'request' => ['othersDown' => true]]]);
});

it('rejoins each node after a forced quorum on the most advanced member', function () {
    $database = idleDatabase('mysql', 3);
    $servers = $database->nodes()->orderBy('position')->get()->map->server->all();
    $ssh = fakeSsh();
    foreach ($servers as $index => $server) {
        answerNodeStatus($ssh, $server, 'offline');
        $ssh->respondWhen(fn (string $name, string $script): bool => $name === $server->name && str_contains($script, '| databasify cluster position '), json_encode(['position' => [5, 9, 7][$index]]));
    }
    fakeEngine($ssh)->respondToEngine('cluster quorum', ['total' => 3, 'online' => 0])->respondToEngine('node wake', [])->respondToEngine('cluster force-quorum', ['leader' => 'x']);

    resolve(RecoverDatabase::class)->handle($database, othersAreDown: true);
    advanceFlows();

    expect(payloadsOf($ssh, 'cluster force-quorum'))->toBe([['server' => $servers[1]->name, 'request' => ['othersDown' => true]]])
        ->and(collect(payloadsOf($ssh, 'cluster rejoin'))->pluck('server')->sort()->values()->all())->toBe(collect([$servers[0]->name, $servers[2]->name])->sort()->values()->all())
        ->and(payloadsOf($ssh, 'node wake'))->toHaveCount(3)
        ->and($database->fresh()->operation)->toBeNull();
});

it('refuses users and databases on engines without accounts', function (string $engine) {
    $ssh = fakeEngine();
    $database = idleDatabase($engine, 1);

    expect(fn () => resolve(CreateDatabaseUser::class)->handle($database, 'reporting'))->toThrow(ValidationException::class, 'have no users or databases to manage')
        ->and(fn () => resolve(CreateSchema::class)->handle($database, 'analytics'))->toThrow(ValidationException::class, 'have no users or databases to manage')
        ->and(payloadsOf($ssh, 'user create'))->toBe([]);
})->with(['valkey', 'redis']);

it('records no user when the engine refuses it', function () {
    fakeEngine(fakeSsh()->respondToEngine('user create', ['error' => ['code' => 'invalid_request', 'message' => 'the name is reserved', 'details' => (object) []]], exit: 1));
    $database = idleDatabase('mysql', 1);

    expect(fn () => resolve(CreateDatabaseUser::class)->handle($database, 'reporting'))->toThrow(InvalidEngineRequest::class, 'the name is reserved')
        ->and($database->users()->where('name', 'reporting')->exists())->toBeFalse();
});

it('grants a new database to every user and drops it again', function () {
    $ssh = fakeEngine()->respondToEngine('database create', ['created' => true])->respondToEngine('user grant', [])->respondToEngine('database drop', ['dropped' => true]);
    $database = idleDatabase('postgres', 1);

    $schema = resolve(CreateSchema::class)->handle($database, 'analytics');
    resolve(DeleteSchema::class)->handle($schema);

    expect(payloadsOf($ssh, 'database create')[0]['request'])->toBe(['name' => 'analytics'])
        ->and(payloadsOf($ssh, 'user grant')[0]['request'])->name->toBe($database->settings->defaultUsername())->replication->toBeFalse()
        ->and(payloadsOf($ssh, 'user grant')[0]['request']['grants'])->toEqualCanonicalizing([['database' => $database->settings->defaultSchema(), 'access' => 'write'], ['database' => 'analytics', 'access' => 'write']])
        ->and(payloadsOf($ssh, 'database drop')[0]['request'])->toBe(['name' => 'analytics'])
        ->and($database->schemas()->where('name', 'analytics')->exists())->toBeFalse();
});

it('resets a password, changes its encryption with the same password and drops the user', function () {
    $ssh = fakeEngine()->respondToEngine('user create', ['created' => true])->respondToEngine('user set-password', [])->respondToEngine('user drop', ['dropped' => true]);
    $database = idleDatabase('mysql', 1);
    $user = resolve(CreateDatabaseUser::class)->handle($database, 'reporting');

    resolve(ResetDatabaseUserPassword::class)->handle($user);
    resolve(ChangePasswordEncryption::class)->handle($user->fresh(), 'mysql_native_password');
    resolve(DeleteDatabaseUser::class)->handle($user->fresh());

    $resets = payloadsOf($ssh, 'user set-password');
    expect($resets[0]['request'])->name->toBe('reporting')->encryption->toBe('caching_sha2_password')
        ->and($resets[1]['request'])->toBe(['name' => 'reporting', 'password' => $resets[0]['request']['password'], 'encryption' => 'mysql_native_password'])
        ->and(payloadsOf($ssh, 'user drop')[0]['request'])->toBe(['name' => 'reporting'])
        ->and($database->users()->where('name', 'reporting')->exists())->toBeFalse();
});

it('tells the owner to fail over while a member still sees a majority', function () {
    $database = idleDatabase('mysql', 3);
    $ssh = fakeSsh();
    $database->nodes->each(fn ($node) => answerNodeStatus($ssh, $node->server, 'offline'));
    fakeEngine($ssh)->respondToEngine('cluster quorum', ['total' => 3, 'online' => 2]);

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'use failover instead');
});

it('rejoins only the members outside a group that still has its majority', function () {
    $database = idleDatabase('mysql', 3);
    [$primary, $replica, $restarted] = $database->nodes()->orderBy('position')->get()->map->server->all();
    $ssh = fakeEngine(answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary'), $replica, 'replica'), $restarted, 'offline'))
        ->respondToEngine('cluster quorum', ['total' => 3, 'online' => 2]);

    resolve(RecoverDatabase::class)->handle($database);
    advanceFlows();

    expect(collect(payloadsOf($ssh, 'cluster rejoin'))->pluck('server')->all())->toBe([$restarted->name])
        ->and(payloadsOf($ssh, 'cluster force-quorum'))->toBe([])
        ->and(payloadsOf($ssh, 'node wake'))->toBe([])
        ->and($database->fresh()->operation)->toBeNull();
});
