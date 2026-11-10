<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\DeployNode;
use App\Engines\Payload;
use App\Engines\Responses\EngineCapabilities;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\FlowState;
use App\Models\Database;
use App\Services\Images\WrapperResolver;

function wrapperDigest(string $character): string
{
    return 'sha256:'.str_repeat($character, 64);
}

function publishWrapper(int $api, string $digest): string
{
    imageRegistry()->publishIn(WrapperResolver::repository(), "api-{$api}", $digest);
    cache()->forget("wrapper-image.{$api}");

    return WrapperResolver::repository().":api-{$api}@{$digest}";
}

function readyDatabaseOnWrapperApi(int $api, int $servers = 2): Database
{
    $database = idleDatabase('postgres', $servers);
    $database->update([
        'status' => DatabaseStatus::Ready,
        'engine_capabilities' => EngineCapabilities::fromPayload(new Payload([...engineCapabilitiesPayload('postgres'), 'wrapperApi' => $api])),
    ]);

    return $database->fresh();
}

it('pushes the newest wrapper of the image api to every node without a restart', function () {
    $database = readyDatabaseOnWrapperApi(1);
    $image = publishWrapper(1, wrapperDigest('c'));
    $ssh = fakeEngine();

    $this->artisan('databases:update-wrappers')->assertSuccessful();
    advanceFlows();

    $commands = engineCommandsIn($ssh);
    $updates = collect($ssh->runs)->keys()->filter(fn (int $index): bool => str_contains($ssh->scriptAt($index), '| databasify host wrapper-update '));

    expect($commands)->toHaveCount(2)
        ->and($database->servers()->map(fn ($server): string => "{$server->name}: host wrapper-update")->sort()->values()->all())->toBe(collect($commands)->sort()->values()->all())
        ->and($updates->map(fn (int $index): array => json_decode($ssh->engineRequestAt($index), true))->unique()->values()->all())->toBe([['image' => $image]])
        ->and($database->fresh())->wrapper_image->toBe($image)->operation->toBeNull()
        ->and($database->flows()->latest('id')->first()->state)->toBe(FlowState::Succeeded);
});

it('starts nothing once the newest wrapper is installed', function () {
    $database = readyDatabaseOnWrapperApi(1);
    $database->update(['wrapper_image' => publishWrapper(1, wrapperDigest('c'))]);
    $ssh = fakeEngine();

    $this->artisan('databases:update-wrappers')->assertSuccessful();

    expect($ssh->runs)->toBeEmpty()
        ->and($database->fresh()->operation)->toBeNull();
});

it('pushes again when a newer wrapper is published', function () {
    $database = readyDatabaseOnWrapperApi(1, 1);
    $database->update(['wrapper_image' => publishWrapper(1, wrapperDigest('c'))]);
    $newer = publishWrapper(1, wrapperDigest('d'));
    $ssh = fakeEngine();

    $this->artisan('databases:update-wrappers')->assertSuccessful();
    advanceFlows();

    expect(engineCommandsIn($ssh))->toHaveCount(1)
        ->and($database->fresh()->wrapper_image)->toBe($newer);
});

it('leaves images without a wrapper api to their next image refresh', function () {
    $database = readyDatabaseOnWrapperApi(0);
    publishWrapper(1, wrapperDigest('c'));
    $ssh = fakeEngine();

    $this->artisan('databases:update-wrappers')->assertSuccessful();

    expect($ssh->runs)->toBeEmpty()
        ->and($database->fresh()->wrapper_image)->toBeNull();
});

it('only offers a database the wrapper built for its own api level', function () {
    $database = readyDatabaseOnWrapperApi(2, 1);
    publishWrapper(1, wrapperDigest('c'));
    $image = publishWrapper(2, wrapperDigest('e'));
    fakeEngine();

    $this->artisan('databases:update-wrappers')->assertSuccessful();
    advanceFlows();

    expect($database->fresh()->wrapper_image)->toBe($image);
});

it('waits while the database is busy', function () {
    $database = readyDatabaseOnWrapperApi(1);
    publishWrapper(1, wrapperDigest('c'));
    $database->startOperation('Apply settings');
    $ssh = fakeEngine();

    $this->artisan('databases:update-wrappers')->assertSuccessful();

    expect($ssh->runs)->toBeEmpty()
        ->and($database->fresh())->wrapper_image->toBeNull()->operation->toBe('Apply settings');
});

it('forgets the installed wrapper when a node gets a new container', function () {
    $database = readyDatabaseOnWrapperApi(1, 1);
    $database->update(['wrapper_image' => publishWrapper(1, wrapperDigest('c'))]);
    fakeEngine();

    resolve(DeployNode::class)->handle($database, $database->nodes()->sole());

    expect($database->fresh()->wrapper_image)->toBeNull();
});

it('keeps the installed wrapper when the same container keeps running', function () {
    $database = readyDatabaseOnWrapperApi(1, 1);
    $database->update(['wrapper_image' => $image = publishWrapper(1, wrapperDigest('c'))]);
    fakeEngine(fakeSsh()->respondToEngine('host deploy', ['engine' => 'postgres', 'container' => 'databasify-postgres', 'created' => false, 'replaced' => false]));

    resolve(DeployNode::class)->handle($database, $database->nodes()->sole());

    expect($database->fresh()->wrapper_image)->toBe($image);
});

it('reads the wrapper api the engine reports', function () {
    $capabilities = EngineCapabilities::fromPayload(new Payload([...engineCapabilitiesPayload('postgres'), 'wrapperApi' => 3]));

    expect($capabilities->wrapperApi)->toBe(3)
        ->and(EngineCapabilities::fromPayload(new Payload(engineCapabilitiesPayload('postgres')))->wrapperApi)->toBe(0)
        ->and(EngineCapabilities::fromPayload(new Payload($capabilities->toArray()))->wrapperApi)->toBe(3);
});
