<?php

declare(strict_types=1);

use App\Actions\Databases\Nodes\DeployNode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\FlowState;
use App\Models\Database;
use App\Services\Images\WrapperResolver;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeImageRegistry;
use Tests\Support\FakeServerConnection;

function pinnedWrapper(): string
{
    return WrapperResolver::repository().':'.WrapperResolver::version().'@'.FakeImageRegistry::wrapperDigest();
}

function readyDatabaseOnWrapper(?string $version, int $servers = 2): Database
{
    $database = idleDatabase('postgres', $servers);
    $database->update(['status' => DatabaseStatus::Ready, 'wrapper_version' => $version]);

    return $database->fresh();
}

/**
 * @return list<string>
 */
function wrapperUpdatesIn(FakeServerConnection $ssh): array
{
    return array_values(array_filter(engineCommandsIn($ssh), fn (string $command): bool => str_ends_with($command, 'host wrapper-update')));
}

it('records the pinned wrapper on a new database', function () {
    expect(idleDatabase('postgres', 1)->wrapper_version)->toBe(WrapperResolver::version());
});

it('refuses the first operation on an older wrapper and installs the pinned one on every node', function () {
    $database = readyDatabaseOnWrapper('0.0.9');
    $ssh = fakeEngine();

    expect(fn () => $database->ensureIdle())->toThrow(ValidationException::class, 'installing databasify '.WrapperResolver::version());
    advanceFlows();

    $requests = collect($ssh->runs)->keys()
        ->filter(fn (int $index): bool => str_contains($ssh->scriptAt($index), '| databasify host wrapper-update '))
        ->map(fn (int $index): array => json_decode($ssh->engineRequestAt($index), true));

    expect(engineCommandsIn($ssh))->toHaveCount(2)->toBe(wrapperUpdatesIn($ssh))
        ->and($requests->unique()->values()->all())->toBe([['image' => pinnedWrapper()]])
        ->and($database->fresh())->wrapper_version->toBe(WrapperResolver::version())->operation->toBeNull()
        ->and($database->flows()->latest('id')->first()->state)->toBe(FlowState::Succeeded);

    $database->fresh()->ensureIdle();
});

it('installs nothing while the database runs the pinned wrapper', function () {
    $database = readyDatabaseOnWrapper(WrapperResolver::version());
    $ssh = fakeEngine();

    $database->ensureIdle();

    expect($ssh->runs)->toBeEmpty();
});

it('lets repairs and image refreshes run on an older wrapper', function () {
    $database = readyDatabaseOnWrapper('0.0.9');
    $ssh = fakeEngine();

    expect(fn () => $database->ensureIdleOnAnyWrapper())->not->toThrow(ValidationException::class)
        ->and($ssh->runs)->toBeEmpty();
});

it('keeps the old version recorded when a node cannot take the new wrapper', function () {
    $database = readyDatabaseOnWrapper('0.0.9', 1);
    $ssh = fakeEngine(fakeSsh()->respondToEngine('host wrapper-update', ['error' => ['code' => 'internal', 'message' => 'the new wrapper does not run', 'details' => (object) []]], 70));

    expect(fn () => $database->ensureIdle())->toThrow(ValidationException::class);
    advanceFlows(40);

    expect($database->fresh()->wrapper_version)->toBe('0.0.9')
        ->and(wrapperUpdatesIn($ssh))->not->toBeEmpty();
});

it('installs the pinned wrapper right after a node gets a new container', function () {
    $database = readyDatabaseOnWrapper(WrapperResolver::version(), 1);
    $ssh = fakeEngine();

    resolve(DeployNode::class)->handle($database, $database->nodes()->sole());

    $commands = engineCommandsIn($ssh);
    $server = $database->nodes()->sole()->server->name;

    expect(array_search("{$server}: host wrapper-update", $commands, true))->toBeGreaterThan(array_search("{$server}: host deploy", $commands, true));
});

it('leaves the wrapper alone when the same container keeps running', function () {
    $database = readyDatabaseOnWrapper(WrapperResolver::version(), 1);
    $ssh = fakeEngine(fakeSsh()->respondToEngine('host deploy', ['engine' => 'postgres', 'container' => 'databasify-postgres', 'created' => false, 'replaced' => false]));

    resolve(DeployNode::class)->handle($database, $database->nodes()->sole());

    expect(wrapperUpdatesIn($ssh))->toBeEmpty();
});
