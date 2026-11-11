<?php

declare(strict_types=1);

use App\Engines\Data\Grant;
use App\Engines\EngineClient;
use App\Engines\Requests\EngineInfo;
use App\Engines\Requests\NodeStatus;
use App\Engines\Requests\UserCreate;
use App\Engines\Responses\EngineInfo as EngineInfoReply;
use App\Engines\Responses\NodeStatus as NodeStatusReply;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\NodeRole;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineNotFound;
use App\Exceptions\Engines\EngineNotRunning;
use App\Exceptions\Engines\EngineUnavailable;
use App\Exceptions\Engines\EngineUnsupported;
use App\Exceptions\Engines\InvalidEngineRequest;
use App\Exceptions\Engines\NotPrimary;

it('sends the request as JSON on stdin and decodes the reply', function () {
    $ssh = fakeSsh()->respondToEngine('node status', ['role' => 'primary', 'version' => '18.6']);
    $node = engineNode();

    $reply = resolve(EngineClient::class)->send($node, new NodeStatus);

    expect($reply)->toBeInstanceOf(NodeStatusReply::class)
        ->role->toBe(NodeRole::Primary)
        ->version->toBe('18.6')
        ->and($ssh->scriptAt(0))->toContain('| base64 -d | databasify node status 2>/dev/null')
        ->and($ssh->engineRequestAt(0))->toBe('{}');
});

it('keeps secrets out of the script and the server log', function () {
    $ssh = fakeSsh()->respondToEngine('user create', ['created' => true]);
    $node = engineNode();

    resolve(EngineClient::class)->send($node, new UserCreate('app', 's3cret-Pw', [new Grant('app', GrantAccess::Write)], null), 'Create user');

    expect($ssh->scriptAt(0))->not->toContain('s3cret-Pw')
        ->and($ssh->engineRequestAt(0))->toBe('{"name":"app","password":"s3cret-Pw","grants":[{"database":"app","access":"write"}],"encryption":null}')
        ->and($node->server->serverLogs()->sole())
        ->name->toBe('Create user')
        ->database_id->toBe($node->database_id)
        ->output->not->toContain('s3cret-Pw');
});

it('maps every engine error code to its exception', function (string $code, string $exception) {
    fakeSsh()->respondToEngine('node status', ['error' => ['code' => $code, 'message' => 'refused', 'details' => (object) []]], exit: 1);
    $node = engineNode();

    expect(fn () => resolve(EngineClient::class)->send($node, new NodeStatus))
        ->toThrow(function (EngineError $error) use ($code, $exception): void {
            expect($error)->toBeInstanceOf($exception)
                ->errorCode->toBe($code)
                ->getMessage()->toBe('refused');
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
    fakeSsh()->respondToEngine('node status', [], exit: 69);
    $node = engineNode();

    expect(fn () => resolve(EngineClient::class)->send($node, new NodeStatus))->toThrow(EngineNotRunning::class);
});

it('refuses a reply that is not JSON', function () {
    fakeSsh()->respond('Segmentation fault');
    $node = engineNode();

    expect(fn () => resolve(EngineClient::class)->send($node, new NodeStatus))->toThrow(EngineError::class);
});

it('decodes the SDK version an engine reports', function () {
    fakeEngine();

    $reply = resolve(EngineClient::class)->send(engineNode(), new EngineInfo);

    expect($reply)->toBeInstanceOf(EngineInfoReply::class)
        ->sdk->toBe('0.1.0')
        ->engine->toBe('postgres');
});
