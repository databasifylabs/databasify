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

function movedWrapperDigest(): string
{
    return 'sha256:'.str_repeat('f', 64);
}

function pinnedWrapperReference(?string $digest = null): string
{
    return WrapperResolver::repository().':'.WrapperResolver::version().'@'.($digest ?? FakeImageRegistry::wrapperDigest());
}

function readyDatabaseOnWrapper(?string $version, ?string $digest, int $servers = 2): Database
{
    $database = idleDatabase('postgres', $servers);
    $database->update(['status' => DatabaseStatus::Ready, 'wrapper_version' => $version, 'wrapper_digest' => $digest]);
    resolve(WrapperResolver::class)->pinned();

    return $database->fresh();
}

function moveWrapperTag(): void
{
    imageRegistry()->publishIn(WrapperResolver::repository(), WrapperResolver::version(), movedWrapperDigest());
    cache()->flush();
    resolve(WrapperResolver::class)->pinned();
}

/**
 * @return list<string>
 */
function wrapperUpdatesIn(FakeServerConnection $ssh): array
{
    return array_values(array_filter(engineCommandsIn($ssh), fn (string $command): bool => str_ends_with($command, 'host wrapper-update')));
}

/**
 * @return list<array<string, mixed>>
 */
function wrapperRequestsIn(FakeServerConnection $ssh): array
{
    return collect($ssh->runs)->keys()
        ->filter(fn (int $index): bool => str_contains($ssh->scriptAt($index), '| databasify host wrapper-update '))
        ->map(fn (int $index): array => json_decode($ssh->engineRequestAt($index), true))
        ->unique()
        ->values()
        ->all();
}

it('records the pinned wrapper version and digest on a new database', function () {
    $database = idleDatabase('postgres', 1);

    expect($database)->wrapper_version->toBe(WrapperResolver::version())->wrapper_digest->toBe(FakeImageRegistry::wrapperDigest());
});

it('refuses the first operation on an older wrapper and installs the pinned one on every node', function () {
    $database = readyDatabaseOnWrapper('0.0.9', 'sha256:'.str_repeat('9', 64));
    $ssh = fakeEngine();

    expect(fn () => $database->ensureIdle())->toThrow(ValidationException::class, 'installing databasify '.WrapperResolver::version());
    advanceFlows();

    expect(engineCommandsIn($ssh))->toHaveCount(2)->toBe(wrapperUpdatesIn($ssh))
        ->and(wrapperRequestsIn($ssh))->toBe([['image' => pinnedWrapperReference()]])
        ->and($database->fresh())->wrapper_version->toBe(WrapperResolver::version())->wrapper_digest->toBe(FakeImageRegistry::wrapperDigest())->operation->toBeNull()
        ->and($database->flows()->latest('id')->first()->state)->toBe(FlowState::Succeeded);

    $database->fresh()->ensureIdle();
});

it('installs the moved build when the pinned version tag points at a new digest', function () {
    $database = readyDatabaseOnWrapper(WrapperResolver::version(), FakeImageRegistry::wrapperDigest(), 1);
    moveWrapperTag();
    $ssh = fakeEngine();

    expect(fn () => $database->ensureIdle())->toThrow(ValidationException::class);
    advanceFlows();

    expect(wrapperRequestsIn($ssh))->toBe([['image' => pinnedWrapperReference(movedWrapperDigest())]])
        ->and($database->fresh()->wrapper_digest)->toBe(movedWrapperDigest());
});

it('follows a moved tag once the cached digest expires', function () {
    $resolver = resolve(WrapperResolver::class);
    $first = $resolver->pinned();
    imageRegistry()->publishIn(WrapperResolver::repository(), WrapperResolver::version(), movedWrapperDigest());

    expect($resolver->pinned())->toEqual($first);

    $this->travel(11)->minutes();

    expect($resolver->pinned()->digest)->toBe(movedWrapperDigest());
});

it('lets the operation run and checks in the background while no digest is cached', function () {
    $database = readyDatabaseOnWrapper(WrapperResolver::version(), FakeImageRegistry::wrapperDigest(), 1);
    cache()->flush();
    $ssh = fakeEngine();

    $database->ensureIdle();

    expect(wrapperUpdatesIn($ssh))->toBeEmpty()
        ->and(WrapperResolver::cached()?->digest)->toBe(FakeImageRegistry::wrapperDigest());
});

it('installs nothing while the database runs the pinned build', function () {
    $database = readyDatabaseOnWrapper(WrapperResolver::version(), FakeImageRegistry::wrapperDigest());
    $ssh = fakeEngine();

    $database->ensureIdle();

    expect($ssh->runs)->toBeEmpty();
});

it('lets repairs and image refreshes run on an older wrapper', function () {
    $database = readyDatabaseOnWrapper('0.0.9', null);
    $ssh = fakeEngine();

    expect(fn () => $database->ensureIdleOnAnyWrapper())->not->toThrow(ValidationException::class)
        ->and($ssh->runs)->toBeEmpty();
});

it('keeps the old build recorded when a node cannot take the new wrapper', function () {
    $database = readyDatabaseOnWrapper('0.0.9', 'sha256:'.str_repeat('9', 64), 1);
    $ssh = fakeEngine(fakeSsh()->respondToEngine('host wrapper-update', ['error' => ['code' => 'internal', 'message' => 'the new wrapper does not run', 'details' => (object) []]], 70));

    expect(fn () => $database->ensureIdle())->toThrow(ValidationException::class);
    advanceFlows(40);

    expect($database->fresh())->wrapper_version->toBe('0.0.9')->wrapper_digest->toBe('sha256:'.str_repeat('9', 64))
        ->and(wrapperUpdatesIn($ssh))->not->toBeEmpty();
});

it('installs the pinned wrapper right after a node gets a new container', function () {
    $database = readyDatabaseOnWrapper(WrapperResolver::version(), FakeImageRegistry::wrapperDigest(), 1);
    $ssh = fakeEngine();

    resolve(DeployNode::class)->handle($database, $database->nodes()->sole());

    $commands = engineCommandsIn($ssh);
    $server = $database->nodes()->sole()->server->name;

    expect(array_search("{$server}: host wrapper-update", $commands, true))->toBeGreaterThan(array_search("{$server}: host deploy", $commands, true));
});

it('leaves the wrapper alone when the same container keeps running', function () {
    $database = readyDatabaseOnWrapper(WrapperResolver::version(), FakeImageRegistry::wrapperDigest(), 1);
    $ssh = fakeEngine(fakeSsh()->respondToEngine('host deploy', ['engine' => 'postgres', 'container' => 'databasify-postgres', 'created' => false, 'replaced' => false]));

    resolve(DeployNode::class)->handle($database, $database->nodes()->sole());

    expect(wrapperUpdatesIn($ssh))->toBeEmpty();
});
