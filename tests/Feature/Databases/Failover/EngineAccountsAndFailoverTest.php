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
use App\DTOs\Databases\QuorumCount;
use App\Engines\Data\Grant;
use App\Engines\Data\Pool;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\PoolMode;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\InvalidEngineRequest;
use Illuminate\Validation\ValidationException;

function serversOf(string $call): array
{
    return array_column(recordedArgs($call), 'server');
}

it('creates a user with grants on the primary', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Replica, NodeRole::Primary);
    $primary = $database->nodes()->orderBy('position')->get()[1]->server;

    resolve(CreateDatabaseUser::class)->handle($database, 'reporting');

    $creates = recordedArgs('accounts.createUser');
    expect($creates)->toHaveCount(1)
        ->and($creates[0]['server'])->toBe($primary->name)
        ->and($creates[0]['args'][0])->toBe('reporting')
        ->and($creates[0]['args'][2])->toEqual([new Grant($database->settings->defaultSchema(), GrantAccess::Write)]);
});

it('applies a pool on every node', function () {
    fakeEngine();
    answerRead('pools.list', [new Pool('old_pool', 'defaultdb', 5, 'session')]);
    $database = idleDatabase('postgres', 2);
    $database->connectionPools()->create(['name' => 'shop_pool', 'schema' => 'defaultdb', 'size' => 20, 'mode' => PoolMode::Transaction]);

    resolve(ApplyConnectionPools::class)->handle($database);

    expect(collect(serversOf('pools.create'))->sort()->values()->all())->toBe($database->nodes->map->server->pluck('name')->sort()->values()->all())
        ->and(recordedArgs('pools.create')[0]['args'])->toEqual([new Pool('shop_pool', 'defaultdb', 20, 'transaction')])
        ->and(recordedArgs('pools.delete')[0]['args'])->toBe(['old_pool']);
});

it('switches the primary with cluster failover', function () {
    $database = idleDatabase('mysql', 2);
    [$primary, $standby] = $database->nodes()->orderBy('position')->get()->all();
    fakeEngine(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $standby->server, 'replica'));

    resolve(PromoteStandby::class)->handle($database);

    expect(recordedArgs('cluster.failover'))->toBe([['server' => $primary->server->name, 'args' => [$standby->privateIp()]]]);
});

it('never forces quorum without the owner confirmation', function () {
    $database = idleDatabase('mysql', 3);
    [$alive, $down, $gone] = $database->nodes()->orderBy('position')->get()->map->server->all();
    fakeEngine(answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $alive, 'offline'), $down, null), $gone, null));
    answerRead('cluster.quorum', new QuorumCount(3, 1));
    answerRead('cluster.position', 10);

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'confirm they are down')
        ->and(recordedArgs('cluster.forceQuorum'))->toBe([]);

    resolve(RecoverDatabase::class)->handle($database->fresh(), othersAreDown: true);
    advanceFlows();

    expect(recordedArgs('cluster.forceQuorum'))->toBe([['server' => $alive->name, 'args' => [true]]]);
});

it('rejoins each node after a forced quorum on the most advanced member', function () {
    $database = idleDatabase('mysql', 3);
    $servers = $database->nodes()->orderBy('position')->get()->map->server->all();
    $ssh = fakeEngine();
    foreach ($servers as $index => $server) {
        answerNodeStatus($ssh, $server, 'offline');
        answerRead('cluster.position', [5, 9, 7][$index], $server);
    }
    answerRead('cluster.quorum', new QuorumCount(3, 0));

    resolve(RecoverDatabase::class)->handle($database, othersAreDown: true);
    advanceFlows();

    expect(recordedArgs('cluster.forceQuorum'))->toBe([['server' => $servers[1]->name, 'args' => [true]]])
        ->and(collect(serversOf('cluster.rejoin'))->sort()->values()->all())->toBe(collect([$servers[0]->name, $servers[2]->name])->sort()->values()->all())
        ->and(recordedArgs('nodes.wake'))->toHaveCount(3)
        ->and($database->fresh()->operation)->toBeNull();
});

it('refuses users and databases on engines without accounts', function (string $engine) {
    fakeEngine();
    $database = idleDatabase($engine, 1);

    expect(fn () => resolve(CreateDatabaseUser::class)->handle($database, 'reporting'))->toThrow(ValidationException::class, 'have no users or databases to manage')
        ->and(fn () => resolve(CreateSchema::class)->handle($database, 'analytics'))->toThrow(ValidationException::class, 'have no users or databases to manage')
        ->and(recordedArgs('accounts.createUser'))->toBe([]);
})->with(['valkey', 'redis']);

it('records no user when the engine refuses it', function () {
    fakeEngine();
    answerRead('accounts.createUser', fn () => throw new InvalidEngineRequest('invalid_request', 'the name is reserved'));
    $database = idleDatabase('mysql', 1);

    expect(fn () => resolve(CreateDatabaseUser::class)->handle($database, 'reporting'))->toThrow(InvalidEngineRequest::class, 'the name is reserved')
        ->and($database->users()->where('name', 'reporting')->exists())->toBeFalse();
});

it('grants a new database to every user and drops it again', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 1);

    $schema = resolve(CreateSchema::class)->handle($database, 'analytics');
    resolve(DeleteSchema::class)->handle($schema);

    $grant = recordedArgs('accounts.grant')[0]['args'];
    expect(recordedArgs('accounts.createDatabase')[0]['args'])->toBe(['analytics'])
        ->and($grant[0])->toBe($database->settings->defaultUsername())
        ->and($grant[2])->toBeFalse()
        ->and($grant[1])->toEqualCanonicalizing([new Grant($database->settings->defaultSchema(), GrantAccess::Write), new Grant('analytics', GrantAccess::Write)])
        ->and(recordedArgs('accounts.dropDatabase')[0]['args'])->toBe(['analytics'])
        ->and($database->schemas()->where('name', 'analytics')->exists())->toBeFalse();
});

it('resets a password, changes its encryption with the same password and drops the user', function () {
    fakeEngine();
    $database = idleDatabase('mysql', 1);
    $user = resolve(CreateDatabaseUser::class)->handle($database, 'reporting');

    resolve(ResetDatabaseUserPassword::class)->handle($user);
    resolve(ChangePasswordEncryption::class)->handle($user->fresh(), 'mysql_native_password');
    resolve(DeleteDatabaseUser::class)->handle($user->fresh());

    $resets = array_column(recordedArgs('accounts.setPassword'), 'args');
    expect($resets[0][0])->toBe('reporting')
        ->and($resets[0][2])->toBe('caching_sha2_password')
        ->and($resets[1])->toBe(['reporting', $resets[0][1], 'mysql_native_password'])
        ->and(recordedArgs('accounts.dropUser')[0]['args'])->toBe(['reporting'])
        ->and($database->users()->where('name', 'reporting')->exists())->toBeFalse();
});

it('tells the owner to fail over while a member still sees a majority', function () {
    $database = idleDatabase('mysql', 3);
    $ssh = fakeEngine();
    $database->nodes->each(fn ($node) => answerNodeStatus($ssh, $node->server, 'offline'));
    answerRead('cluster.quorum', new QuorumCount(3, 2));

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'use failover instead');
});

it('rejoins only the members outside a group that still has its majority', function () {
    $database = idleDatabase('mysql', 3);
    [$primary, $replica, $restarted] = $database->nodes()->orderBy('position')->get()->map->server->all();
    fakeEngine(answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary'), $replica, 'replica'), $restarted, 'offline'));
    answerRead('cluster.quorum', new QuorumCount(3, 2));

    resolve(RecoverDatabase::class)->handle($database);
    advanceFlows();

    expect(serversOf('cluster.rejoin'))->toBe([$restarted->name])
        ->and(recordedArgs('cluster.forceQuorum'))->toBe([])
        ->and(recordedArgs('nodes.wake'))->toBe([])
        ->and($database->fresh()->operation)->toBeNull();
});

it('leaves a member that just stopped answering out of a recovery', function () {
    $database = idleDatabase('mysql', 3);
    [$primary, $replica, $silent] = $database->nodes()->orderBy('position')->get()->all();
    markRoles($database, NodeRole::Primary, NodeRole::Replica, NodeRole::Offline);
    fakeEngine(answerNodeStatus(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary->server, 'primary'), $replica->server, 'replica'), $silent->server, null));
    answerRead('cluster.quorum', new QuorumCount(3, 2));

    expect(fn () => resolve(RecoverDatabase::class)->handle($database))->toThrow(ValidationException::class, 'use failover instead')
        ->and(recordedArgs('cluster.rejoin'))->toBe([]);
});

it('starts a member whose container stopped before it rejoins the group', function () {
    $database = idleDatabase('mysql', 3);
    [$primary, $replica, $stopped] = $database->nodes()->orderBy('position')->get()->map->server->all();
    $ssh = fakeEngine(answerNodeStatus(answerNodeStatus(fakeSsh(), $primary, 'primary'), $replica, 'replica'));
    answerRead('nodes.status', fn () => throw new EngineNotRunning('not_running', "No engine container is running on {$stopped->name}."), $stopped);
    answerRead('cluster.quorum', new QuorumCount(3, 2));

    resolve(RecoverDatabase::class)->handle($database);
    advanceFlows();

    $deploys = collect($ssh->runs)->filter(fn (array $run): bool => str_contains($run['script'], '| databasify host deploy '))->pluck('server')->values()->all();
    expect($deploys)->toBe([$stopped->name])
        ->and(serversOf('cluster.rejoin'))->toBe([$stopped->name])
        ->and($database->fresh()->operation)->toBeNull();
});
