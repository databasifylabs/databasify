<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Models\Database;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->freezeSecond();
    $this->actingAs(User::factory()->create());
    Queue::fake([BootstrapDatabaseJob::class]);
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

function archiveStatusUpTo(CarbonInterface $latest): string
{
    return 'backups=2'."\nearliest=".now()->subDay()->getTimestamp()."\nlatest={$latest->getTimestamp()}\nlast_backup=".now()->subHours(5)->getTimestamp()."\n";
}

function restoreTo(Database $source, CarbonInterface $target): TestResponse
{
    return test()->post("/databases/{$source->id}/recovery/restore", ['name' => 'orders-restored', 'server_id' => Server::factory()->ready()->create()->id, 'target' => $target->toIso8601String()]);
}

it('re-reads a stale archive window before refusing a target past it', function () {
    $source = staleWindowDatabase();
    $ssh = fakeSsh()->respond(archiveStatusUpTo(now()->subMinute()));

    restoreTo($source, now()->subMinutes(5))->assertSessionHasNoErrors();

    expect(Database::query()->where('name', 'orders-restored')->exists())->toBeTrue()
        ->and($ssh->runs)->toHaveCount(1)
        ->and($source->pointInTimeRecovery?->fresh()?->latest_restorable_at?->getTimestamp())->toBe(now()->subMinute()->getTimestamp());
});

it('restores without reaching the server when the window already covers the target', function () {
    $source = staleWindowDatabase();
    $ssh = fakeSsh();

    restoreTo($source, now()->subHour())->assertSessionHasNoErrors();

    expect($ssh->runs)->toBe([]);
});

it('refuses with the refreshed window when the archive still has not reached the target', function () {
    $source = staleWindowDatabase();
    fakeSsh()->respond(archiveStatusUpTo(now()->subMinutes(10)));

    restoreTo($source, now()->subMinutes(5))
        ->assertSessionHasErrors(['target' => 'orders cannot be restored to '.now()->subMinutes(5)->utc()->format('Y-m-d H:i:s').' UTC. Pick a time between '.now()->subDay()->utc()->format('Y-m-d H:i:s').' and '.now()->subMinutes(10)->utc()->format('Y-m-d H:i:s').' UTC.']);

    expect(Database::query()->count())->toBe(1);
});

it('does not reach the server for a target the archive can never cover', function (Closure $target) {
    $source = staleWindowDatabase();
    $ssh = fakeSsh();

    restoreTo($source, $target())->assertSessionHasErrors('target');

    expect($ssh->runs)->toBe([]);
})->with([
    'in the future' => [fn () => now()->addHour()],
    'before the earliest backup' => [fn () => now()->subDays(3)],
]);
