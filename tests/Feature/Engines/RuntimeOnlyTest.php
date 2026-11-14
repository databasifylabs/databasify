<?php

declare(strict_types=1);

use App\Actions\Databases\Accounts\CreateDatabaseUser;
use App\Actions\Databases\Lifecycle\RefreshNodeStatuses;
use App\Actions\Databases\Monitoring\ReadDatabaseMetrics;
use App\Actions\Databases\Recovery\RefreshRecoveryStatus;
use App\DTOs\Databases\EngineImage;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Models\Database;
use App\Support\Engines\SdkRange;
use Illuminate\Validation\ValidationException;

it('accepts sdk 1.0 images and the runtime releases of 0.2', function (?string $sdk, bool $allowed) {
    expect(SdkRange::allows($sdk))->toBe($allowed);
})->with([
    '1.0.0' => ['1.0.0', true],
    '1.4.2' => ['1.4.2', true],
    '0.2.2' => ['0.2.2', true],
    '0.2.1' => ['0.2.1', false],
    '0.3.0' => ['0.3.0', false],
    'none' => [null, false],
]);

it('makes no engine call for a database without the engine runtime', function () {
    fakeEngine();
    $database = idleDatabase('postgres', 1);
    $database->update(['image' => new EngineImage($database->image->repository, $database->image->digest, $database->image->release, null)]);

    expect(fn () => resolve(CreateDatabaseUser::class)->handle($database->fresh(), 'reporting'))
        ->toThrow(ValidationException::class, 'which this Databasify no longer supports')
        ->and(recordedCalls())->toBe([]);
});

function onDroppedSdk(string $engine, int $servers): Database
{
    $database = idleDatabase($engine, $servers);
    $database->update(['image' => new EngineImage($database->image->repository, $database->image->digest, $database->image->release, '0.2.1')]);

    return $database->fresh();
}

it('keeps the last known roles of a database on a dropped sdk without polling its engine', function () {
    fakeEngine();
    $database = onDroppedSdk('mysql', 2);
    markRoles($database, NodeRole::Primary, NodeRole::Replica);
    $database->recordStatus(DatabaseStatus::Ready);

    $status = resolve(RefreshNodeStatuses::class)->handle($database->fresh(), $database->nodesWithServers());

    expect($status)->toBe(DatabaseStatus::Ready)
        ->and($database->nodes()->orderBy('position')->pluck('role')->all())->toBe([NodeRole::Primary, NodeRole::Replica])
        ->and(recordedCalls())->toBe([]);
});

it('reads no engine metrics or archive status of a database on a dropped sdk', function () {
    fakeEngine();
    $database = withRecovery(onDroppedSdk('postgres', 1));
    markRoles($database, NodeRole::Primary);

    resolve(ReadDatabaseMetrics::class)->handle($database->fresh());
    resolve(RefreshRecoveryStatus::class)->handle($database->fresh()->pointInTimeRecovery);

    expect(recordedCalls())->toBe([]);
});
