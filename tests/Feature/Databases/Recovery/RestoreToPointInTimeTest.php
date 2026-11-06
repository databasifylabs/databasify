<?php

declare(strict_types=1);

use App\Actions\Databases\Lifecycle\BootstrapDatabase;
use App\DTOs\Recovery\RecoverySource;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\Persistence;
use App\Jobs\Databases\BootstrapDatabaseJob;
use App\Models\Database;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\Ssh;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->freezeSecond();
});

function restorableDatabase(?string $engine = null): Database
{
    $engine ??= 'mysql';

    return withRecovery(idleDatabase($engine, 1, DatabaseMode::Single, 'orders', $engine === 'postgres' ? '17' : null), restorable: true);
}

it('restores into a new database with the same engine line, secrets and accounts', function () {
    $source = restorableDatabase('postgres');
    $source->users()->create(['name' => 'reporting', 'password' => 'secret-pass']);
    $source->schemas()->create(['name' => 'analytics']);
    $server = Server::factory()->ready()->create();
    $target = now()->subHour()->startOfSecond();
    Queue::fake([BootstrapDatabaseJob::class]);
    withImage('postgres', '17.99');

    $this->post("/databases/{$source->id}/recovery/restore", ['name' => 'orders-restored', 'server_id' => $server->id, 'target' => $target->toIso8601String()])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/databases/'.Database::query()->where('name', 'orders-restored')->value('id'));

    $restored = Database::query()->where('name', 'orders-restored')->sole();
    expect($restored)
        ->engine->toBe('postgres')
        ->version_line->toBe('17')
        ->mode->value->toBe('single')
        ->and($restored->image())->toEqual($source->image())
        ->and($restored->settings->secrets)->toBe($source->settings->secrets)
        ->and($restored->settings->restore)->toEqual(new RecoverySource('orders', $source->pointInTimeRecovery->storage_provider_id, $source->pointInTimeRecovery->path, CarbonImmutable::instance($target)->utc()))
        ->and($restored->pointInTimeRecovery)->toBeNull()
        ->and($restored->nodes()->sole()->server_id)->toBe($server->id)
        ->and($restored->users()->pluck('name')->all())->toBe(['databasify', 'reporting'])
        ->and($restored->schemas()->pluck('name')->all())->toBe(['analytics', 'defaultdb'])
        ->and($source->fresh()?->nodes()->count())->toBe(1);
    Queue::assertPushed(BootstrapDatabaseJob::class);
});

it('restores with the settings the source database runs with', function () {
    $source = restorableDatabase('postgres');
    $source->update(['settings' => $source->settings->withConfig(['work_mem' => '64MB', 'log_min_duration_statement' => '500'])->withPersistence(Persistence::Off)]);
    $server = Server::factory()->ready()->create();
    Queue::fake([BootstrapDatabaseJob::class]);

    $this->post("/databases/{$source->id}/recovery/restore", ['name' => 'orders-restored', 'server_id' => $server->id, 'target' => now()->subHour()->toIso8601String()])
        ->assertSessionHasNoErrors();

    $restored = Database::query()->where('name', 'orders-restored')->sole();
    expect($restored->settings->config)->toBe(['work_mem' => '64MB', 'log_min_duration_statement' => '500'])
        ->and($restored->settings->persistence)->toBe(Persistence::Off);
    Queue::assertPushed(BootstrapDatabaseJob::class, fn (BootstrapDatabaseJob $job): bool => $job->database->is($restored) && $job->database->settings->config === $restored->settings->config);
});

it('refuses a moment outside the restorable window', function () {
    $source = restorableDatabase();
    $window = $source->pointInTimeRecovery;
    $server = Server::factory()->ready()->create();

    $this->post("/databases/{$source->id}/recovery/restore", ['name' => 'orders-restored', 'server_id' => $server->id, 'target' => now()->addHour()->toIso8601String()])
        ->assertSessionHasErrors(['target' => 'orders cannot be restored to '.now()->addHour()->utc()->format('Y-m-d H:i:s')." UTC. Pick a time between {$window?->earliest_restorable_at?->utc()->format('Y-m-d H:i:s')} and {$window?->latest_restorable_at?->utc()->format('Y-m-d H:i:s')} UTC."]);

    expect(Database::query()->count())->toBe(1);
});

it('refuses a restore before the first base backup', function () {
    $source = withRecovery(idleDatabase('mariadb', 1, DatabaseMode::Single, 'orders'));

    $this->post("/databases/{$source->id}/recovery/restore", ['name' => 'orders-restored', 'server_id' => Server::factory()->ready()->create()->id, 'target' => now()->toIso8601String()])
        ->assertSessionHasErrors(['target' => 'orders cannot be restored to '.now()->utc()->format('Y-m-d H:i:s').' UTC. The archive has no complete base backup yet.']);
});

it('validates the restore request', function (array $input, string $field) {
    $source = restorableDatabase();
    $free = Server::factory()->ready()->create();

    $this->post("/databases/{$source->id}/recovery/restore", [...['name' => 'orders-restored', 'server_id' => $free->id, 'target' => now()->subHour()->toIso8601String()], ...$input])
        ->assertSessionHasErrors($field);
})->with([
    'taken name' => [['name' => 'orders'], 'name'],
    'server in use' => [['server_id' => 'source'], 'server_id'],
    'no target' => [['target' => ''], 'target'],
    'not a date' => [['target' => 'yesterday-ish'], 'target'],
]);

it('restores the archive onto the new seed before it starts and keeps its accounts', function () {
    $this->travelTo('2026-10-26 07:30:15');
    $source = restorableDatabase();
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->post("/databases/{$source->id}/recovery/restore", ['name' => 'orders-restored', 'server_id' => Server::factory()->ready()->create()->id, 'target' => '2026-10-26T06:30:15+00:00']);
    $restored = Database::query()->where('name', 'orders-restored')->sole();
    $source->pointInTimeRecovery?->update(['earliest_restorable_at' => null]);
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.crt', 'server.key', 'ca.key'));
    foreach (range(1, 6) as $ignored) {
        $ssh->respond();
    }
    $ssh->respond("PRIMARY ONLINE\n");

    resolve(BootstrapDatabase::class)->handle($restored);

    $restore = $ssh->scriptAt(2);
    expect($ssh->scriptAt(1))->toContain('docker run -d --name databasify-mysql')->not->toContain('binlog-archive')
        ->and($restore)
        ->toContain("--stop-datetime='2026-10-26 06:30:15'")
        ->toContain("awk '$2 <= 1792996215'")
        ->toContain('xbstream -x -C "$dir/data"')
        ->toContain('DROP SCHEMA IF EXISTS mysql_innodb_cluster_metadata')
        ->toContain('echo "could not list the archived binlogs"')
        ->and(base64_decode(Str::afterLast(Str::before($restore, "' | base64 -d > \"\$work/source.env\""), "echo '")))
        ->toContain("ARCHIVE='archive:archives/mysql/{$source->dataKey()}'")
        ->and($ssh->runs[2]['timeout'])->toBe(Ssh::LONG_TIMEOUT)
        ->and($ssh->scriptAt(4))->toContain('databasify ctl restart mysql')
        ->and(sqlSentIn($ssh->scriptAt(6)))->toBe('')
        ->and($restored->serverLogs()->pluck('name'))->toContain("{$restored->seed()->server->name}: restoring orders as it was at 2026-10-26 06:30:15 UTC");
});

it('restores postgres with pgbackrest up to the target and clears the recovery settings', function () {
    $this->travelTo('2026-10-26 07:30:15');
    $source = restorableDatabase('postgres');
    Queue::fake([BootstrapDatabaseJob::class]);
    $this->post("/databases/{$source->id}/recovery/restore", ['name' => 'orders-restored', 'server_id' => Server::factory()->ready()->create()->id, 'target' => '2026-10-26T06:30:15+00:00']);
    $restored = Database::query()->where('name', 'orders-restored')->sole();
    $ssh = fakeSsh()->respond(certificateOutput('ca.crt', 'server.crt', 'server.key', 'ca.key'));

    rescue(fn () => resolve(BootstrapDatabase::class)->handle($restored), report: false);

    $restore = $ssh->scriptAt(2);
    expect($ssh->scriptAt(1))->toContain('docker run -d --name databasify-postgres')
        ->and($restore)
        ->toContain("--type=time --target='2026-10-26 06:30:15+00' --target-action=promote")
        ->toContain('archive-get %f "%p"')
        ->toContain('ALTER SYSTEM RESET $setting')
        ->and(fileWrittenIn($restore, '/srv/postgres/conf/pgbackrest-restore.conf'))
        ->toContain("repo1-path=/postgres/{$source->dataKey()}")
        ->not->toContain('retention');
});
