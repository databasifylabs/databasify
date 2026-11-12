<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\LearnEngine;
use App\Engines\Data\Grant;
use App\Engines\Drivers\Postgres\PostgresDriver;
use App\Engines\EngineClient;
use App\Engines\Payload;
use App\Engines\Requests\EngineInfo;
use App\Engines\Requests\OperationStatus;
use App\Engines\Requests\UserCreate;
use App\Engines\Responses\EngineCapabilities;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\OperationState;
use Tests\Support\FakeEngineDriver;

beforeEach(function () {
    FakeEngineDriver::$answered = [];
    config(['engine.providers.postgres.driver' => FakeEngineDriver::class]);
});

function createUserRequest(): UserCreate
{
    return new UserCreate('app', 'pw', [new Grant('app', GrantAccess::Write)], null);
}

it('keeps sending requests to the engine binary while the switch is off', function () {
    $ssh = fakeSsh()->respondToEngine('user create', ['created' => true]);

    resolve(EngineClient::class)->send(engineNode(), createUserRequest());

    expect($ssh->scriptAt(0))->toContain('databasify user create')
        ->and(FakeEngineDriver::$answered)->toBe([]);
});

it('lets the driver answer a listed engine once the switch is on', function () {
    config(['databasify.php_engines' => ['postgres']]);
    $ssh = fakeSsh()->respondToEngine('engine info', ['engine' => 'postgres', 'line' => '18', 'version' => '18.6', 'sdk' => '0.2.2']);
    $node = engineNode();

    $reply = resolve(EngineClient::class)->send($node, createUserRequest());
    resolve(EngineClient::class)->send($node, new EngineInfo);

    expect($reply->created)->toBeTrue()
        ->and(FakeEngineDriver::$answered)->toBe(['user create'])
        ->and($ssh->scriptAt(0))->toContain('databasify engine info');
});

it('keeps a database on an image without the runtime on the binary', function () {
    config(['databasify.php_engines' => ['postgres']]);
    $ssh = fakeSsh()->respondToEngine('user create', ['created' => true]);
    $node = engineNode();
    $capabilities = engineCapabilitiesPayload('postgres');
    $capabilities['commands'] = array_values(array_diff($capabilities['commands'], ['runtime run']));
    $node->database->update(['engine_capabilities' => EngineCapabilities::fromPayload(new Payload($capabilities))]);

    resolve(EngineClient::class)->send($node->fresh(), createUserRequest());

    expect($ssh->scriptAt(0))->toContain('databasify user create')
        ->and(FakeEngineDriver::$answered)->toBe([]);
});

it('moves a database to the driver once an upgrade reports the runtime', function () {
    config(['databasify.php_engines' => ['postgres']]);
    $node = engineNode();
    $old = engineCapabilitiesPayload('postgres');
    $old['commands'] = array_values(array_diff($old['commands'], ['runtime run']));
    $node->database->update(['engine_capabilities' => EngineCapabilities::fromPayload(new Payload($old))]);
    fakeSsh()->respondToEngine('engine capabilities', engineCapabilitiesPayload('postgres'))->respondToEngine('config fields', ['fields' => []]);

    resolve(LearnEngine::class)->handle($node->database->fresh(), $node);
    resolve(EngineClient::class)->send($node->fresh(), createUserRequest());

    expect(FakeEngineDriver::$answered)->toBe(['user create']);
});

it('leaves engines that are not listed on the binary', function () {
    config(['databasify.php_engines' => ['postgres']]);
    $ssh = fakeSsh()->respondToEngine('user create', ['created' => true]);
    $node = idleDatabase('mysql', 1)->nodes()->sole();

    resolve(EngineClient::class)->send($node, createUserRequest());

    expect($ssh->scriptAt(0))->toContain('databasify user create')
        ->and(FakeEngineDriver::$answered)->toBe([]);
});

it('reports a task-backed operation with the phase from its log', function () {
    config(['databasify.php_engines' => ['postgres'], 'engine.providers.postgres.driver' => PostgresDriver::class]);
    fakeSsh()
        ->respondToEngine('task status', ['key' => 'ready.1', 'kind' => 'task', 'state' => 'interrupted', 'phase' => 'starting', 'progress' => null, 'startedAt' => '2026-11-12T00:00:00Z', 'finishedAt' => null, 'error' => null])
        ->respondToEngine('task log', ['lines' => "DATABASIFY_PHASE copying\n"]);

    $operation = resolve(EngineClient::class)->send(engineNode(), new OperationStatus('ready.1'));

    expect($operation->state)->toBe(OperationState::Interrupted)
        ->and($operation->phase)->toBe('copying');
});
