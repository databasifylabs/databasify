<?php

declare(strict_types=1);

use App\Contracts\Engines\EngineScripts;
use App\Engines\Scripts\ScriptRunner;
use App\Engines\Scripts\ScriptView;
use App\Enums\Servers\ServerLogStatus;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\EngineScriptFailed;
use App\Exceptions\Engines\NotPrimary;

function runtimeReply(int $exit, string $stdout = '', string $stderr = ''): array
{
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'truncated' => false];
}

it('renders the script with escaped values and sends it on stdin', function () {
    $ssh = fakeSsh()->respondToEngine('runtime run', runtimeReply(0, "it's\n"));

    $result = resolve(ScriptRunner::class)->run(engineNode(), 'engines.testing.echo', ['word' => "it's"], user: 'postgres');

    expect($result->stdout)->toBe("it's\n")
        ->and(json_decode($ssh->engineRequestAt(0), true))->toMatchArray(['script' => "echo 'it'\\''s'\n", 'user' => 'postgres', 'timeoutSeconds' => 120]);
});

it('decodes the one result line', function () {
    fakeSsh()->respondToEngine('runtime run', runtimeReply(0, "noise\nDATABASIFY_RESULT {\"role\":\"primary\"}\n"));

    $result = resolve(ScriptRunner::class)->run(engineNode(), 'engines.testing.echo', ['word' => 'x']);

    expect($result->json()->string('role'))->toBe('primary');
});

it('fails the server log when the engine cannot be reached', function () {
    fakeSsh()->respond('', 69);
    $node = engineNode();

    expect(fn () => resolve(ScriptRunner::class)->run($node, 'engines.testing.echo', ['word' => 'x'], log: 'Create user'))
        ->toThrow(EngineNotRunning::class);

    $log = $node->server->serverLogs()->sole();
    expect($log->status)->toBe(ServerLogStatus::Failed)
        ->and($log->output)->toContain('No engine container is running');
});

it('fails with the last stderr line and keeps the tail in the server log', function () {
    fakeSsh()->respondToEngine('runtime run', runtimeReply(2, '', "a\nb\nboom\n"));
    $node = engineNode();

    expect(fn () => resolve(ScriptRunner::class)->run($node, 'engines.testing.echo', ['word' => 'x'], log: 'Create user'))
        ->toThrow(EngineScriptFailed::class, 'engines.testing.echo exited 2: boom');

    $log = $node->server->serverLogs()->sole();
    expect($log->status)->toBe(ServerLogStatus::Failed)
        ->and($log->output)->toContain("a\nb\nboom");
});

it('renders the real view in the fake', function () {
    $scripts = fakeScripts()->respond('engines.testing.echo', ['said' => 'yes']);

    $node = engineNode();

    $result = resolve(EngineScripts::class)->run($node, 'engines.testing.echo', ['word' => "it's"]);

    expect($result->json()->string('said'))->toBe('yes');
    $scripts->assertRan('engines.testing.echo', fn (string $script): bool => $script === resolve(ScriptView::class)->render($node, 'engines.testing.echo', ['word' => "it's"]));
});

it('passes values in the env, not in the script', function () {
    $ssh = fakeSsh()->respondToEngine('runtime run', runtimeReply(0));

    resolve(ScriptRunner::class)->run(engineNode(), 'engines.testing.echo', ['word' => 'x'], env: ['PASSWORD' => "p'w"]);

    expect(json_decode($ssh->engineRequestAt(0), true)['env'])->toBe(['DATABASIFY_ENGINE' => 'postgres', 'DATABASIFY_LINE' => '18', 'HOME' => '/root', 'PASSWORD' => "p'w"]);
});

it('turns a typed error line into the matching engine error', function () {
    fakeSsh()->respondToEngine('runtime run', runtimeReply(1, "DATABASIFY_ERROR not_primary\nthis node is a replica; run the command on the primary\n"));

    expect(fn () => resolve(ScriptRunner::class)->run(engineNode(), 'engines.testing.echo', ['word' => 'x']))
        ->toThrow(NotPrimary::class, 'this node is a replica; run the command on the primary');
});

it('reduces a psql statement failure to the server message', function () {
    fakeSsh()->respondToEngine('runtime run', runtimeReply(1, "DATABASIFY_ERROR sql\npsql:<stdin>:3: ERROR:  role \"x\" already exists\nLINE 1: CREATE ROLE x\n        ^\nHINT:  try another\n"));

    expect(fn () => resolve(ScriptRunner::class)->run(engineNode(), 'engines.testing.echo', ['word' => 'x']))
        ->toThrow(EngineError::class, 'ERROR:  role "x" already exists');
});

it('gives every script the version line so it can branch on it', function (string $line, string $expected) {
    $node = engineNode();
    $node->database->update(['version_line' => $line]);
    $scripts = fakeScripts();

    resolve(EngineScripts::class)->run($node->fresh(), 'engines.testing.version');

    $scripts->assertRan('engines.testing.version', fn (string $script): bool => trim($script) === $expected);
})->with([
    'older line' => ['8.4', 'echo old'],
    'newer line' => ['9.7', 'echo new'],
]);

it('tells the in-container tools which engine they serve, because the runtime starts scripts with an empty environment', function () {
    $ssh = fakeSsh()->respondToEngine('task start', ['key' => 'k.1', 'kind' => 'task', 'state' => 'running', 'phase' => 'starting', 'progress' => null, 'startedAt' => '2026-11-13T00:00:00Z', 'finishedAt' => null, 'error' => null]);
    $node = idleDatabase('valkey', 1, versionLine: '9')->nodes()->sole();

    resolve(ScriptRunner::class)->start($node, 'k.1', 'engines.testing.echo', ['word' => 'x'], env: ['DATABASIFY_ENGINE' => 'spoofed']);

    expect(json_decode($ssh->engineRequestAt(0), true)['env'])->toMatchArray(['DATABASIFY_ENGINE' => 'valkey', 'DATABASIFY_LINE' => '9', 'HOME' => '/root']);
});
