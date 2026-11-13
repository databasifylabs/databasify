<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\LearnEngine;
use App\Engines\Data\Grant;
use App\Engines\Drivers\MongoDb\MongoDbDriver;
use App\Engines\Drivers\Sentinel\ValkeyDriver;
use App\Engines\EngineClient;
use App\Engines\Payload;
use App\Engines\Requests\EngineInfo;
use App\Engines\Requests\OperationCancel;
use App\Engines\Requests\OperationStatus;
use App\Engines\Requests\UserCreate;
use App\Engines\Responses\Done;
use App\Engines\Responses\EngineCapabilities;
use App\Enums\Databases\GrantAccess;
use App\Enums\Databases\OperationState;
use Tests\Support\FakeEngineDriver;

beforeEach(function () {
    FakeEngineDriver::$answered = [];
    config(['engine.providers.postgres.driver' => FakeEngineDriver::class, 'engine.providers.mysql.driver' => FakeEngineDriver::class, 'engine.providers.mariadb.driver' => FakeEngineDriver::class, 'engine.providers.mongodb.driver' => FakeEngineDriver::class, 'engine.providers.valkey.driver' => FakeEngineDriver::class, 'engine.providers.redis.driver' => FakeEngineDriver::class]);
});

function createUserRequest(): UserCreate
{
    return new UserCreate('app', 'pw', [new Grant('app', GrantAccess::Write)], null);
}

it('keeps sending requests to the engine binary while the switch is off', function (string $engine) {
    $ssh = fakeSsh()->respondToEngine('user create', ['created' => true]);

    resolve(EngineClient::class)->send(idleDatabase($engine, 1)->nodes()->sole(), createUserRequest());

    expect($ssh->scriptAt(0))->toContain('databasify user create')
        ->and(FakeEngineDriver::$answered)->toBe([]);
})->with(['postgres', 'mysql', 'mariadb', 'mongodb', 'valkey', 'redis']);

it('lets the driver answer a listed engine once the switch is on', function (string $engine) {
    config(['databasify.php_engines' => [$engine]]);
    $ssh = fakeSsh()->respondToEngine('engine info', ['engine' => $engine, 'line' => '1', 'version' => '1.0', 'sdk' => '0.2.2']);
    $node = idleDatabase($engine, 1)->nodes()->sole();

    $reply = resolve(EngineClient::class)->send($node, createUserRequest());
    resolve(EngineClient::class)->send($node, new EngineInfo);

    expect($reply->created)->toBeTrue()
        ->and(FakeEngineDriver::$answered)->toBe(['user create'])
        ->and($ssh->scriptAt(0))->toContain('databasify engine info');
})->with(['postgres', 'mysql', 'mariadb', 'mongodb', 'valkey', 'redis']);

it('keeps a database on an image without the runtime on the binary', function (string $engine) {
    config(['databasify.php_engines' => [$engine]]);
    $ssh = fakeSsh()->respondToEngine('user create', ['created' => true]);
    $node = idleDatabase($engine, 1)->nodes()->sole();
    $capabilities = engineCapabilitiesPayload($engine);
    $capabilities['commands'] = array_values(array_diff($capabilities['commands'], ['runtime run']));
    $node->database->update(['engine_capabilities' => EngineCapabilities::fromPayload(new Payload($capabilities))]);

    resolve(EngineClient::class)->send($node->fresh(), createUserRequest());

    expect($ssh->scriptAt(0))->toContain('databasify user create')
        ->and(FakeEngineDriver::$answered)->toBe([]);
})->with(['postgres', 'mysql', 'mariadb', 'mongodb', 'valkey', 'redis']);

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
    config(['databasify.php_engines' => ['postgres'], 'engine.providers.valkey.driver' => null]);
    $ssh = fakeSsh()->respondToEngine('user create', ['created' => true]);
    $node = idleDatabase('valkey', 1)->nodes()->sole();

    resolve(EngineClient::class)->send($node, createUserRequest());

    expect($ssh->scriptAt(0))->toContain('databasify user create')
        ->and(FakeEngineDriver::$answered)->toBe([]);
});

it('reports a task-backed operation with the phase from its log', function () {
    config(['databasify.php_engines' => ['mongodb'], 'engine.providers.mongodb.driver' => MongoDbDriver::class]);
    fakeSsh()
        ->respondToEngine('task status', ['key' => 'ready.1', 'kind' => 'task', 'state' => 'interrupted', 'phase' => 'starting', 'progress' => null, 'startedAt' => '2026-11-12T00:00:00Z', 'finishedAt' => null, 'error' => null])
        ->respondToEngine('task log', ['lines' => "DATABASIFY_PHASE copying\n"]);

    $operation = resolve(EngineClient::class)->send(idleDatabase('mongodb', 1)->nodes()->sole(), new OperationStatus('ready.1'));

    expect($operation->state)->toBe(OperationState::Interrupted)
        ->and($operation->phase)->toBe('copying');
});

it('cancels a task-backed operation and answers done', function (string $engine, string $driver) {
    config(['databasify.php_engines' => [$engine], "engine.providers.{$engine}.driver" => $driver]);
    $ssh = fakeSsh()->respondToEngine('task stop', ['key' => 'k.1', 'kind' => 'task', 'state' => 'interrupted', 'phase' => 'stopping', 'progress' => null, 'startedAt' => '2026-11-13T00:00:00Z', 'finishedAt' => null, 'error' => null]);

    $reply = resolve(EngineClient::class)->send(idleDatabase($engine, 1)->nodes()->sole(), new OperationCancel('k.1'));

    expect($reply)->toBeInstanceOf(Done::class)
        ->and($ssh->scriptAt(0))->toContain('databasify task stop');
})->with([
    'mongodb' => ['mongodb', MongoDbDriver::class],
    'valkey' => ['valkey', ValkeyDriver::class],
]);
