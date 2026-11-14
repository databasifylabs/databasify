<?php

declare(strict_types=1);

use App\Engines\Drivers\Postgres\PostgresDriver;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\OperationState;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\NotPrimary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Tests\Support\ScriptedDriver;

function useScriptedDriver(): void
{
    app()->bind(PostgresDriver::class, fn ($app, array $parameters): ScriptedDriver => new ScriptedDriver($parameters['node'], $parameters['followsPrimary'] ?? false));
}

function scriptRun(int $exit, string $stdout): array
{
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => '', 'truncated' => false];
}

it('writes what a change script prints to a log named after the script', function () {
    useScriptedDriver();
    fakeSsh()->respondToEngine('runtime run', scriptRun(0, "Created user app.\n"));
    $node = engineNode();

    $node->driver()->run('engines.testing.echo', ['word' => 'x']);

    expect($node->server->serverLogs()->sole())
        ->name->toBe("{$node->server->name}: echo")
        ->status->toBe(ServerLogStatus::Succeeded)
        ->output->toContain('Created user app.');
});

it('runs a plugin script from its own view namespace', function () {
    useScriptedDriver();
    $ssh = fakeSsh()->respondToEngine('runtime run', scriptRun(0, "Started KeyDB.\n"));
    $directory = sys_get_temp_dir().'/plugin-views-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$directory}/nodes");
    File::put("{$directory}/nodes/start-server.blade.php", "echo 'Started KeyDB.'\n");
    View::addNamespace('keydb', $directory);
    $node = engineNode();

    $node->driver()->run('keydb::nodes.start-server');
    File::deleteDirectory($directory);

    expect(json_decode($ssh->engineRequestAt(0), true)['script'])->toBe("echo 'Started KeyDB.'\n")
        ->and($node->server->serverLogs()->sole()->name)->toBe("{$node->server->name}: start server");
});

it('fails the log and throws the typed error when a script calls databasify_fail', function () {
    useScriptedDriver();
    fakeSsh()->respondToEngine('runtime run', scriptRun(1, "DATABASIFY_ERROR busy\nrun node configure first\n"));
    $node = engineNode();

    expect(fn () => $node->driver()->run('engines.testing.echo', ['word' => 'x']))->toThrow(EngineBusy::class, 'run node configure first')
        ->and($node->server->serverLogs()->sole()->status)->toBe(ServerLogStatus::Failed);
});

it('reads one JSON object and leaves no log', function () {
    useScriptedDriver();
    fakeSsh()->respondToEngine('runtime run', scriptRun(0, '{"role":"primary"}'."\n"));
    $node = engineNode();

    expect($node->driver()->read('engines.testing.echo', ['word' => 'x'])->string('role'))->toBe('primary')
        ->and($node->server->serverLogs()->count())->toBe(0);
});

it('runs once more on the new primary after not_primary', function () {
    useScriptedDriver();
    $database = idleDatabase('postgres', 2);
    [$first, $second] = $database->nodes()->orderBy('position')->get()->all();
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $ssh = fakeSsh()
        ->respondWhen(fn (string $server, string $script): bool => $server === $first->server->name && str_contains($script, '| databasify runtime run '), json_encode(scriptRun(1, "DATABASIFY_ERROR not_primary\na replica now\n")))
        ->respondWhen(fn (string $server, string $script): bool => $server === $second->server->name && str_contains($script, '| databasify runtime run '), json_encode(scriptRun(0, "done\n")));
    answerNodeStatus(answerNodeStatus($ssh, $first->server, 'replica'), $second->server, 'primary');

    $database->driver()->run('engines.testing.echo', ['word' => 'x']);

    expect(collect($ssh->runs)->filter(fn (array $run): bool => str_contains($run['script'], '| databasify runtime run '))->pluck('server')->values()->all())
        ->toBe([$first->server->name, $second->server->name]);
});

it('gives up after a second not_primary', function () {
    useScriptedDriver();
    $database = idleDatabase('postgres', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $ssh = fakeSsh()->respondToEngine('runtime run', scriptRun(1, "DATABASIFY_ERROR not_primary\na replica now\n"));
    $database->nodes()->with('server')->get()->each(fn ($node) => answerNodeStatus($ssh, $node->server, 'replica'));

    expect(fn () => $database->driver()->run('engines.testing.echo', ['word' => 'x']))->toThrow(NotPrimary::class, 'a replica now');
});

it('returns the existing task when a task is started again with its key', function () {
    useScriptedDriver();
    $ssh = fakeSsh()->respondToEngine('task start', ['key' => 'run1.ready', 'kind' => 'task', 'state' => 'succeeded', 'phase' => '', 'progress' => null, 'startedAt' => '2026-11-13T00:00:00Z', 'finishedAt' => null, 'error' => null]);
    $driver = engineNode()->driver();

    $first = $driver->task('run1.ready', 'engines.testing.echo', ['word' => 'x']);
    $again = $driver->task('run1.ready', 'engines.testing.echo', ['word' => 'x']);

    expect($again->state)->toBe(OperationState::Succeeded)
        ->and($first->key)->toBe($again->key)
        ->and(json_decode($ssh->engineRequestAt(1), true)['key'])->toBe('run1.ready');
});

it('refuses a group the engine does not offer', function () {
    useScriptedDriver();

    expect(fn () => engineNode()->driver()->accounts())->toThrow(EngineUnsupported::class, 'this engine does not implement "user create"');
});

it('reuses a running log with the same name', function () {
    $node = engineNode();

    expect($node->openLog('server: joining the cluster')->id)->toBe($node->openLog('server: joining the cluster')->id);
});

it('reports a task with the phase from its log', function () {
    fakeSsh()
        ->respondToEngine('task status', ['key' => 'ready.1', 'kind' => 'task', 'state' => 'running', 'phase' => 'starting', 'progress' => null, 'startedAt' => '2026-11-12T00:00:00Z', 'finishedAt' => null, 'error' => null])
        ->respondToEngine('task log', ['lines' => "DATABASIFY_PHASE copying\n"]);

    $operation = idleDatabase('valkey', 1)->nodes()->sole()->driver()->taskStatus('ready.1');

    expect($operation->state)->toBe(OperationState::Running)
        ->and($operation->phase)->toBe('copying');
});

it('stops a task', function () {
    $ssh = fakeSsh()->respondToEngine('task stop', ['key' => 'k.1', 'kind' => 'task', 'state' => 'interrupted', 'phase' => 'stopping', 'progress' => null, 'startedAt' => '2026-11-13T00:00:00Z', 'finishedAt' => null, 'error' => null]);

    $operation = idleDatabase('valkey', 1)->nodes()->sole()->driver()->stopTask('k.1');

    expect($operation->state)->toBe(OperationState::Interrupted)
        ->and($ssh->scriptAt(0))->toContain('databasify task stop');
});

it('reads no task log once a task has finished', function () {
    $ssh = fakeSsh()->respondToEngine('task status', ['key' => 'ready.1', 'kind' => 'task', 'state' => 'succeeded', 'phase' => '', 'progress' => null, 'startedAt' => '2026-11-12T00:00:00Z', 'finishedAt' => '2026-11-12T00:01:00Z', 'error' => null]);

    $operation = idleDatabase('valkey', 1)->nodes()->sole()->driver()->taskStatus('ready.1');

    expect($operation->state)->toBe(OperationState::Succeeded)
        ->and(collect($ssh->runs)->filter(fn (array $run): bool => str_contains($run['script'], '| databasify task log ')))->toBeEmpty();
});

it('keeps the reported phase of a running task when its log cannot be read', function () {
    fakeSsh()
        ->respondToEngine('task status', ['key' => 'ready.1', 'kind' => 'task', 'state' => 'running', 'phase' => 'starting', 'progress' => null, 'startedAt' => '2026-11-12T00:00:00Z', 'finishedAt' => null, 'error' => null])
        ->respondToEngine('task log', ['error' => ['code' => 'not_found', 'message' => 'no log', 'details' => (object) []]], exit: 1);

    $operation = idleDatabase('valkey', 1)->nodes()->sole()->driver()->taskStatus('ready.1');

    expect($operation->state)->toBe(OperationState::Running)->and($operation->phase)->toBe('starting');
});
