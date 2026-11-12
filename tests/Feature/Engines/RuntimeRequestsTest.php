<?php

declare(strict_types=1);

use App\Engines\EngineClient;
use App\Engines\Requests\FileGet;
use App\Engines\Requests\FilePut;
use App\Engines\Requests\RuntimeRun;
use App\Engines\Requests\ServiceControl;
use App\Engines\Requests\TaskLog;
use App\Engines\Requests\TaskStart;

it('runs a script with an empty env sent as an object', function () {
    $ssh = fakeSsh()->respondToEngine('runtime run', ['exit' => 3, 'stdout' => "hi\n", 'stderr' => 'oops', 'truncated' => false]);

    $reply = resolve(EngineClient::class)->send(engineNode(), new RuntimeRun('echo hi', [], 'postgres', 30));

    expect($reply)->exit->toBe(3)->stdout->toBe("hi\n")->stderr->toBe('oops')->truncated->toBeFalse()
        ->and($ssh->engineRequestAt(0))->toBe('{"script":"echo hi","env":{},"user":"postgres","timeoutSeconds":30}');
});

it('starts a task under its key', function () {
    $ssh = fakeSsh()->respondToEngine('task start', ['key' => 'copy.1', 'kind' => 'task', 'state' => 'running', 'phase' => 'starting', 'progress' => null, 'startedAt' => '2026-11-12T00:00:00Z', 'finishedAt' => null, 'error' => null]);

    $operation = resolve(EngineClient::class)->send(engineNode(), new TaskStart('copy.1', 'sleep 1', ['A' => 'b'], null));

    expect($operation->key)->toBe('copy.1')
        ->and($ssh->engineRequestAt(0))->toBe('{"key":"copy.1","script":"sleep 1","env":{"A":"b"},"user":null}');
});

it('encodes file content as base64 and decodes it back', function () {
    $ssh = fakeSsh()->respondToEngine('file put', [])->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);

    $node = engineNode();

    resolve(EngineClient::class)->send($node, new FilePut('conf/pgpass', "a'b", 0o600, 'postgres'));
    $missing = resolve(EngineClient::class)->send($node, new FileGet('conf/nope'));

    expect($ssh->engineRequestAt(0))->toBe('{"path":"conf/pgpass","contentBase64":"'.base64_encode("a'b").'","mode":384,"owner":"postgres"}')
        ->and($missing->exists)->toBeFalse()
        ->and($missing->content)->toBeNull();
});

it('reads a task log and reloads services with an empty request', function () {
    $ssh = fakeSsh()->respondToEngine('task log', ['lines' => "a\nb\n"])->respondToEngine('service reload', []);

    $node = engineNode();

    $log = resolve(EngineClient::class)->send($node, new TaskLog('copy.1'));
    resolve(EngineClient::class)->send($node, new ServiceControl('reload', []));

    expect($log->lines)->toBe("a\nb\n")
        ->and($ssh->engineRequestAt(1))->toBe('{}');
});
