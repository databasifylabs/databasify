<?php

declare(strict_types=1);

use App\Exceptions\Databases\OperationStopped;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use App\Services\Databases\NodeRunner;
use App\Services\Ssh\SshSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use phpseclib3\Net\SFTP;

it('runs a step on every node and groups their logs', function () {
    $database = idleDatabase('mysql', 3);
    fakeSsh()->respond()->respond()->respond();

    resolve(NodeRunner::class)->each($database->nodesWithServers(), fn (DatabaseNode $node) => $node->ssh()->exec('ssh.databases.noop', log: "{$node->server->name}: noop"), 'running noop on 3 nodes');

    expect(ServerLog::count())->toBe(3)
        ->and(ServerLog::query()->distinct()->pluck('group_name')->all())->toBe(['running noop on 3 nodes'])
        ->and(ServerLog::query()->distinct()->count('group_id'))->toBe(1);
});

it('finishes every node before rethrowing the first failure', function () {
    $database = idleDatabase('mysql', 3);
    fakeSsh()->respond('', 1)->respond()->respond();

    expect(fn () => resolve(NodeRunner::class)->each($database->nodesWithServers(), fn (DatabaseNode $node) => $node->ssh()->exec('ssh.databases.noop', log: "{$node->server->name}: noop"), 'noop'))
        ->toThrow(RemoteScriptFailed::class);
    expect(ServerLog::count())->toBe(3);
});

it('does not start steps after a stop request', function () {
    $database = idleDatabase('mysql', 3);
    $database->requestStop();
    $ssh = fakeSsh();

    expect(fn () => resolve(NodeRunner::class)->each($database->nodesWithServers(), fn (DatabaseNode $node) => $node->ssh()->exec('ssh.databases.noop', log: 'noop'), 'noop'))
        ->toThrow(OperationStopped::class);
    expect($ssh->runs)->toBeEmpty();
});

it('runs read-only groups one after another', function () {
    $calls = [];
    $nodes = idleDatabase('mysql', 8)->nodesWithServers();

    resolve(NodeRunner::class)->inGroupsOf($nodes, function (DatabaseNode $node) use (&$calls): void {
        $calls[] = $node->id;
    }, 'rolling');

    expect($calls)->toBe($nodes->pluck('id')->all());
});

it('reads a value from every node in node order', function () {
    $nodes = idleDatabase('mysql', 3)->nodesWithServers();

    expect(resolve(NodeRunner::class)->map($nodes, fn (DatabaseNode $node): DatabaseNode => $node, DatabaseNode::class)->pluck('id')->all())->toBe($nodes->pluck('id')->all());
});

it('rethrows the first failed read once every node has been read', function () {
    $nodes = idleDatabase('mysql', 3)->nodesWithServers();
    $read = [];
    $readEvery = function (DatabaseNode $node) use ($nodes, &$read): DatabaseNode {
        $read[] = $node->id;

        return $node->is($nodes[1]) ? throw new RuntimeException('position unreadable') : $node;
    };

    expect(fn () => resolve(NodeRunner::class)->map($nodes, $readEvery, DatabaseNode::class))->toThrow(RuntimeException::class, 'position unreadable');
    expect($read)->toBe($nodes->pluck('id')->all());
});

it('rethrows a stop from a read as a stop', function () {
    $database = idleDatabase('mysql', 3);
    $database->requestStop();
    fakeSsh();

    expect(fn () => resolve(NodeRunner::class)->map($database->nodesWithServers(), fn (DatabaseNode $node): DatabaseNode => tap($node, fn () => $node->ssh()->exec('ssh.databases.noop', log: 'noop')), DatabaseNode::class))
        ->toThrow(OperationStopped::class);
});

it('keeps connections in the process that started the pass', function () {
    $sessions = new SshSessions;
    $server = idleDatabase('mysql', 1)->nodes()->first()->server;
    $session = Mockery::mock(SFTP::class, ['isConnected' => true, 'disconnect' => null]);
    $sessions->sessionFor($server, fn (): SFTP => $session);
    $connection = DB::connection();

    (new NodeRunner($sessions))->reconnectUnless(getmypid());

    expect(DB::connection())->toBe($connection)
        ->and($sessions->sessionFor($server, fn (): SFTP => Mockery::mock(SFTP::class)))->toBe($session);
});

it('drops inherited SSH sessions and database connections in a forked child', function () {
    $sessions = new SshSessions;
    $server = idleDatabase('mysql', 1)->nodes()->first()->server;
    $inherited = Mockery::mock(SFTP::class, ['isConnected' => true]);
    $inherited->shouldNotReceive('disconnect');
    $own = Mockery::mock(SFTP::class, ['isConnected' => true, 'disconnect' => null]);
    $sessions->sessionFor($server, fn (): SFTP => $inherited);
    $open = ['sqlite' => DB::connection()];
    DB::partialMock()->shouldReceive('getConnections')->andReturn($open);
    DB::shouldReceive('purge')->once()->with('sqlite');
    Redis::shouldReceive('purge')->once();

    (new NodeRunner($sessions))->reconnectUnless(getmypid() + 1);

    expect($sessions->sessionFor($server, fn (): SFTP => $own))->toBe($own);
});

it('keeps the worker alive and finishes forked steps when it is told to stop', function () {
    config(['concurrency.default' => 'fork']);
    pcntl_async_signals(true);
    $stopRequested = false;
    pcntl_signal(SIGTERM, function () use (&$stopRequested): void {
        $stopRequested = true;
    });
    $worker = getmypid();

    try {
        resolve(NodeRunner::class)->run([
            fn (): bool => posix_kill($worker, SIGTERM),
            fn () => usleep(200_000),
        ], 'stopping mid-step');
    } finally {
        pcntl_signal(SIGTERM, SIG_DFL);
    }

    expect($stopRequested)->toBeTrue();
});
