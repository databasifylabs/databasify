<?php

declare(strict_types=1);

use App\Engines\Data\HostProfile;
use App\Engines\Runtime\Runtime;
use App\Enums\Databases\OperationState;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\EngineUnavailable;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Exceptions\Engines\NotPrimary;

function runtimeTask(string $state): array
{
    return ['key' => 'copy.1', 'kind' => 'task', 'state' => $state, 'phase' => '', 'progress' => null, 'startedAt' => '2026-11-13T00:00:00Z', 'finishedAt' => null, 'error' => null];
}

it('sends a script on stdin with its env as a JSON object', function () {
    $ssh = fakeSsh()->respondToEngine('runtime run', ['exit' => 0, 'stdout' => "hi\n", 'stderr' => '', 'truncated' => false]);

    $run = resolve(Runtime::class)->run(engineNode(), 'echo hi', [], 'postgres', 30);

    expect($ssh->engineRequestAt(0))->toBe('{"script":"echo hi","env":{},"user":"postgres","timeoutSeconds":30}')
        ->and($run->exit)->toBe(0)
        ->and($run->stdout)->toBe("hi\n");
});

it('starts, reads and stops a task by key', function () {
    $ssh = fakeSsh()
        ->respondToEngine('task start', runtimeTask('running'))
        ->respondToEngine('task status', runtimeTask('succeeded'))
        ->respondToEngine('task stop', runtimeTask('cancelled'))
        ->respondToEngine('task log', ['lines' => "a\nb\n"]);
    $node = engineNode();
    $runtime = resolve(Runtime::class);

    expect($runtime->startTask($node, 'copy.1', 'sleep 1', ['A' => 'b'], null)->state)->toBe(OperationState::Running)
        ->and($runtime->taskStatus($node, 'copy.1')->state)->toBe(OperationState::Succeeded)
        ->and($runtime->stopTask($node, 'copy.1')->state)->toBe(OperationState::Cancelled)
        ->and($runtime->taskLog($node, 'copy.1'))->toBe("a\nb\n")
        ->and($ssh->engineRequestAt(0))->toBe('{"key":"copy.1","script":"sleep 1","env":{"A":"b"},"user":null}')
        ->and($ssh->engineRequestAt(1))->toBe('{"key":"copy.1"}');
});

it('reads a missing file as null', function () {
    fakeSsh()->respondToEngine('file get', ['contentBase64' => '', 'exists' => false]);

    expect(resolve(Runtime::class)->getFile(engineNode(), 'conf/missing'))->toBeNull();
});

it('writes a file with its mode and owner', function () {
    $ssh = fakeSsh()->respondToEngine('file put', []);

    resolve(Runtime::class)->putFile(engineNode(), 'conf/b', 'data', 0o600, null);

    expect($ssh->engineRequestAt(0))->toBe('{"path":"conf/b","contentBase64":"ZGF0YQ==","mode":384,"owner":null}');
});

it('deploys with the host profile and returns the image engine label', function () {
    $ssh = fakeSsh()->respondToEngine('host deploy', ['engine' => 'postgres', 'container' => 'databasify-postgres', 'created' => true, 'replaced' => false]);
    $node = engineNode();

    $engine = resolve(Runtime::class)->deploy($node, 'ghcr.io/databasifylabs/databasify-postgres:18.6@sha256:abc', new HostProfile(['vm.swappiness' => '1']));

    expect($engine)->toBe('postgres')
        ->and($ssh->engineRequestAt(0))->toContain('"floors":{}')
        ->and(json_decode($ssh->engineRequestAt(0), true))->toMatchArray(['databaseId' => $node->database_id, 'nodeId' => $node->id]);
});

it('reads the engine info and the logs', function () {
    fakeSsh()
        ->respondToEngine('engine info', ['engine' => 'postgres', 'line' => '18', 'version' => '18.6', 'sdk' => '0.2.2'])
        ->respondToEngine('logs read', ['units' => ['postgres'], 'lines' => ['ready']]);
    $node = engineNode();
    $runtime = resolve(Runtime::class);

    expect($runtime->engineInfo($node)->sdk)->toBe('0.2.2')
        ->and($runtime->readLogs($node, null, 1)->units)->toBe(['postgres']);
});

it('maps every engine error code to its exception', function (string $code, string $exception) {
    fakeSsh()->respondToEngine('engine info', ['error' => ['code' => $code, 'message' => 'refused', 'details' => (object) []]], exit: 1);

    expect(fn () => resolve(Runtime::class)->engineInfo(engineNode()))
        ->toThrow(function (EngineError $error) use ($code, $exception): void {
            expect($error)->toBeInstanceOf($exception)->errorCode->toBe($code)->getMessage()->toBe('refused');
        });
})->with([
    ['invalid_request', InvalidEngineRequest::class],
    ['unsupported', EngineUnsupported::class],
    ['not_found', EngineNotFound::class],
    ['already_exists', EngineAlreadyExists::class],
    ['not_primary', NotPrimary::class],
    ['busy', EngineBusy::class],
    ['unavailable', EngineUnavailable::class],
    ['internal', EngineError::class],
]);

it('maps exit 69 to EngineNotRunning', function () {
    fakeSsh()->respondToEngine('engine info', [], exit: 69);

    expect(fn () => resolve(Runtime::class)->engineInfo(engineNode()))->toThrow(EngineNotRunning::class);
});

it('refuses a reply that is not JSON', function () {
    fakeSsh()->respond('Segmentation fault');

    expect(fn () => resolve(Runtime::class)->engineInfo(engineNode()))->toThrow(EngineError::class);
});
