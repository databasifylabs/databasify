<?php

declare(strict_types=1);

use App\Actions\Databases\Configuration\RetuneNode;
use App\Actions\Databases\Configuration\UpdateNodeConfig;
use App\Actions\Databases\Failover\FailoverDatabase;
use App\Actions\Databases\Network\AddNodeTrustedSource;
use App\Actions\Databases\Nodes\RebootNode;
use App\Actions\Databases\Pools\CreateNodeConnectionPool;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NewConnectionPool;
use App\DTOs\Databases\NewTrustedSource;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\PoolMode;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('runs an operation on a read-only node without blocking the main', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);

    $reader->markBusy(DatabaseStatus::Updating);

    expect($database->fresh()?->isBusy())->toBeFalse()
        ->and(fn () => $reader->fresh()?->markBusy(DatabaseStatus::Rebooting))->toThrow(ValidationException::class, "{$reader->displayName()} is busy updating, try again when it finishes.");
});

it('refuses a cascading operation while one read-only node is busy and claims nothing', function () {
    $database = idleDatabase('postgres', 1);
    $busy = withReadOnlyNode($database);
    $free = withReadOnlyNode($database);
    $busy->markBusy(DatabaseStatus::Updating);

    expect(fn () => $database->markBusyWithReadOnly(DatabaseStatus::Upgrading, fn (): null => null))->toThrow(ValidationException::class, 'is busy updating');

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($free->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('claims every read-only node with a cascading operation and releases them when it ends', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);

    $database->markBusyWithReadOnly(DatabaseStatus::Upgrading, fn (): null => null);

    expect($reader->fresh()?->status)->toBe(DatabaseStatus::Upgrading);

    $database->markReady();

    expect($reader->fresh()?->status)->toBe(DatabaseStatus::Ready);
});

it('leaves a read-only node own operation alone when a main operation ends', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $reader->markBusy(DatabaseStatus::Rebooting);
    $database->markBusy(DatabaseStatus::Updating);

    $database->markReady();

    expect($reader->fresh()?->status)->toBe(DatabaseStatus::Rebooting);
});

it('refuses a read-only node operation while the database is being deleted', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $database->markBusy(DatabaseStatus::Deleting);

    expect(fn () => $reader->fresh()?->markBusy(DatabaseStatus::Updating))->toThrow(ValidationException::class, 'main is deleting, wait until it is ready.');
});

it('keeps stop requests apart between the main and a read-only node', function () {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);

    $reader->requestStop();

    expect($reader->isStopRequested())->toBeTrue()
        ->and($database->isStopRequested())->toBeFalse();
});

it('refuses read-only node changes on a dropped SDK but still reboots the node', function (Closure $change) {
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $image = $database->image;
    $database->update(['image' => new EngineImage($image->repository, $image->digest, $image->release, '0.0.9')]);
    Queue::fake();

    expect(fn () => $change($reader->fresh()))->toThrow(ValidationException::class, 'no longer supports');

    resolve(RebootNode::class)->handle($reader->fresh());
    expect($reader->fresh()?->status)->toBe(DatabaseStatus::Rebooting);
})->with([
    'trusted source' => [fn ($node) => resolve(AddNodeTrustedSource::class)->handle($node, NewTrustedSource::normalized('198.51.100.7/32', null))],
    'pool' => [fn ($node) => resolve(CreateNodeConnectionPool::class)->handle($node, new NewConnectionPool('reports', 'defaultdb', PoolMode::Transaction, 10))],
    'config' => [fn ($node) => resolve(UpdateNodeConfig::class)->handle($node, ['work_mem' => '32MB'])],
    'retune' => [fn ($node) => resolve(RetuneNode::class)->handle($node)],
]);

it('fails over while a read-only node runs its own operation', function () {
    Queue::fake();
    $database = idleDatabase('postgres', 2);
    $reader = withReadOnlyNode($database);
    $reader->markBusy(DatabaseStatus::Rebooting);

    resolve(FailoverDatabase::class)->handle($database);

    expect($database->fresh()?->status)->toBe(DatabaseStatus::Recovering)
        ->and($reader->fresh()?->status)->toBe(DatabaseStatus::Rebooting);
});

it('releases a read-only node when stop is pressed again after its job is gone', function () {
    useRecordingDrivers();
    $database = idleDatabase('postgres', 1);
    $reader = withReadOnlyNode($database);
    $reader->markBusy(DatabaseStatus::Rebooting);
    $reader->requestStop();

    $this->actingAs(User::factory()->create())->post(projectUrl("/databases/{$database->id}/nodes/{$reader->id}/stop"))->assertSessionHasNoErrors();

    expect($reader->fresh()?->status)->toBe(DatabaseStatus::Ready)
        ->and($reader->fresh()?->isStopRequested())->toBeFalse();
});
