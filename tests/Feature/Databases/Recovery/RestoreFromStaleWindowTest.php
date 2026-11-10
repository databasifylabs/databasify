<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\Database;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeServerConnection;

beforeEach(function () {
    $this->freezeSecond();
    $this->actingAs(User::factory()->create());
    Queue::fake([AdvanceDatabaseFlow::class]);
});

function staleWindowDatabase(): Database
{
    $database = idleDatabase('mysql', 1, DatabaseMode::Single, 'orders');
    markRoles($database, NodeRole::Primary);

    return withRecovery($database, [
        'earliest_restorable_at' => now()->subDay(),
        'latest_restorable_at' => now()->subMinutes(30),
        'checked_at' => now()->subMinutes(30),
    ], restorable: true);
}

function archiveStatusUpTo(CarbonInterface $latest): FakeServerConnection
{
    return fakeEngine(fakeEngine()->respondToEngine('archive status', ['backups' => 2, 'earliest' => now()->subDay()->toIso8601ZuluString(), 'latest' => $latest->toIso8601ZuluString(), 'lastBackup' => now()->subHours(5)->toIso8601ZuluString(), 'problem' => null]));
}

function restoreTo(Database $source, CarbonInterface $target): TestResponse
{
    return test()->post(projectUrl("/databases/{$source->id}/recovery/restore"), ['name' => 'orders-restored', 'server_id' => Server::factory()->ready()->create()->id, 'target' => $target->toIso8601String()]);
}

it('re-reads a stale archive window before refusing a target past it', function () {
    $source = staleWindowDatabase();
    $ssh = archiveStatusUpTo(now()->subMinute());

    restoreTo($source, now()->subMinutes(5))->assertSessionHasNoErrors();

    expect(Database::query()->where('name', 'orders-restored')->exists())->toBeTrue()
        ->and($ssh->runs)->toHaveCount(1)
        ->and($source->pointInTimeRecovery?->fresh()?->latest_restorable_at?->getTimestamp())->toBe(now()->subMinute()->getTimestamp());
});

it('restores without reaching the server when the window already covers the target', function () {
    $source = staleWindowDatabase();
    $ssh = fakeEngine();

    restoreTo($source, now()->subHour())->assertSessionHasNoErrors();

    expect($ssh->runs)->toBe([]);
});

it('refuses with the refreshed window when the archive still has not reached the target', function () {
    $source = staleWindowDatabase();
    archiveStatusUpTo(now()->subMinutes(10));

    restoreTo($source, now()->subMinutes(5))
        ->assertSessionHasErrors(['target' => 'orders cannot be restored to '.now()->subMinutes(5)->utc()->format('Y-m-d H:i:s').' UTC. Pick a time between '.now()->subDay()->utc()->format('Y-m-d H:i:s').' and '.now()->subMinutes(10)->utc()->format('Y-m-d H:i:s').' UTC.']);

    expect(Database::query()->count())->toBe(1);
});

it('does not reach the server for a target the archive can never cover', function (Closure $target) {
    $source = staleWindowDatabase();
    $ssh = fakeEngine();

    restoreTo($source, $target())->assertSessionHasErrors('target');

    expect($ssh->runs)->toBe([]);
})->with([
    'in the future' => [fn () => now()->addHour()],
    'before the earliest backup' => [fn () => now()->subDays(3)],
]);

it('gives the restored database its own point-in-time recovery on the same storage', function () {
    $source = staleWindowDatabase();
    fakeEngine();

    restoreTo($source, now()->subHour())->assertSessionHasNoErrors();

    $restored = Database::query()->where('name', 'orders-restored')->sole();
    expect($restored->pointInTimeRecovery)
        ->storage_provider_id->toBe($source->pointInTimeRecovery?->storage_provider_id)
        ->retention_days->toBe($source->pointInTimeRecovery?->retention_days)
        ->backup_hour->toBe($source->pointInTimeRecovery?->backup_hour)
        ->path->not->toBe($source->pointInTimeRecovery?->path);
});
