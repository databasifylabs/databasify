<?php

declare(strict_types=1);

use App\Actions\Databases\Recovery\RefreshRecoveryStatus;
use App\Actions\Databases\Recovery\UpdateRecoverySchedule;
use App\DTOs\Recovery\RecoverySchedule;
use App\Enums\Databases\DatabaseMode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Enums\Recovery\ArchiveHealth;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Jobs\Databases\RefreshRecoveryStatusJob;
use App\Models\PointInTimeRecovery;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('turns on point-in-time recovery and redeploys every node to start archiving', function (string $engine) {
    $this->freezeSecond();
    $database = idleDatabase($engine, 1, DatabaseMode::Single, 'orders');
    $provider = StorageProvider::factory()->create(['path_prefix' => 'team']);
    Queue::fake([AdvanceDatabaseFlow::class]);

    $this->post(projectUrl("/databases/{$database->id}/recovery"), ['storage_provider_id' => $provider->id, 'retention_days' => 14, 'backup_hour' => 2])->assertSessionHasNoErrors();

    $recovery = PointInTimeRecovery::query()->sole();
    expect($recovery)
        ->database_id->toBe($database->id)
        ->path->toBe("team/{$engine}/{$database->dataKey()}/".now()->format('YmdHis'))
        ->retention_days->toBe(14)
        ->backup_hour->toBe(2)
        ->health->toBe(ArchiveHealth::Pending)
        ->and($database->fresh()->operation)->toBe('Turn on point-in-time recovery');
    Queue::assertPushed(AdvanceDatabaseFlow::class);
})->with(['postgres', 'mysql', 'mariadb']);

it('refuses point-in-time recovery where it cannot work', function () {
    $valkey = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    $postgres = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    $provider = StorageProvider::factory()->create();
    $input = ['storage_provider_id' => $provider->id, 'retention_days' => 7, 'backup_hour' => 3];

    $this->post(projectUrl("/databases/{$valkey->id}/recovery"), $input)->assertSessionHasErrors(['recovery' => 'Valkey has no point-in-time recovery.']);
    $this->post(projectUrl("/databases/{$postgres->id}/recovery"), $input)->assertSessionHasErrors(['recovery' => 'orders already has point-in-time recovery.']);

    expect(PointInTimeRecovery::query()->count())->toBe(1);
});

it('validates the recovery settings', function (array $input, string $field) {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $provider = StorageProvider::factory()->create();

    $this->post(projectUrl("/databases/{$database->id}/recovery"), [...['storage_provider_id' => $provider->id, 'retention_days' => 7, 'backup_hour' => 3], ...$input])->assertSessionHasErrors($field);

    expect(PointInTimeRecovery::query()->count())->toBe(0);
})->with([
    'unknown storage' => [['storage_provider_id' => 999], 'storage_provider_id'],
    'no retention' => [['retention_days' => 0], 'retention_days'],
    'too long retention' => [['retention_days' => 36], 'retention_days'],
    'bad hour' => [['backup_hour' => 24], 'backup_hour'],
]);

it('refuses to turn it on while another operation runs', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single, 'orders');
    $database->startOperation('Upgrade');

    $this->post(projectUrl("/databases/{$database->id}/recovery"), ['storage_provider_id' => StorageProvider::factory()->create()->id, 'retention_days' => 7, 'backup_hour' => 3])
        ->assertSessionHasErrors(['database' => 'orders is busy with Upgrade, try again when it finishes.']);
});

it('turns it off, keeping the archive in the bucket', function () {
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'));
    Queue::fake([AdvanceDatabaseFlow::class]);

    $this->delete(projectUrl("/databases/{$database->id}/recovery"))->assertSessionHasNoErrors();

    expect(PointInTimeRecovery::query()->count())->toBe(0)
        ->and(StorageProvider::query()->count())->toBe(1)
        ->and($database->fresh()->operation)->toBe('Turn off point-in-time recovery');
    Queue::assertPushed(AdvanceDatabaseFlow::class);
});

it('changes the schedule without a restart', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    Queue::fake([AdvanceDatabaseFlow::class]);

    $this->put(projectUrl("/databases/{$database->id}/recovery"), ['retention_days' => 30, 'backup_hour' => 5])->assertSessionHasNoErrors();

    expect($database->pointInTimeRecovery?->fresh())->retention_days->toBe(30)->backup_hour->toBe(5)
        ->and($database->fresh()->operation)->toBe('Update point-in-time recovery');
    Queue::assertPushed(AdvanceDatabaseFlow::class);
});

it('asks for point-in-time recovery before backing up or checking', function () {
    $database = idleDatabase('postgres', 1, DatabaseMode::Single, 'orders');

    $this->post(projectUrl("/databases/{$database->id}/recovery/backup"))->assertSessionHasErrors(['recovery' => 'orders has no point-in-time recovery, turn it on first.']);
    $this->post(projectUrl("/databases/{$database->id}/recovery/check"))->assertSessionHasErrors('recovery');
    $this->put(projectUrl("/databases/{$database->id}/recovery"), ['retention_days' => 7, 'backup_hour' => 3])->assertSessionHasErrors('recovery');
    $this->delete(projectUrl("/databases/{$database->id}/recovery"))->assertSessionHasErrors('recovery');
});

it('tells engines without archiving that they have no backups to take', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');

    $this->post(projectUrl("/databases/{$database->id}/recovery/backup"))->assertSessionHasErrors(['recovery' => 'Valkey has no point-in-time recovery.']);
});

it('starts a base backup on request', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    Queue::fake([RefreshRecoveryStatusJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/recovery/backup"))->assertSessionHasNoErrors();

    Queue::assertPushed(RefreshRecoveryStatusJob::class, fn (RefreshRecoveryStatusJob $job): bool => $job->backUpNow);
});

it('checks the archive on request', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    Queue::fake([RefreshRecoveryStatusJob::class]);

    $this->post(projectUrl("/databases/{$database->id}/recovery/check"))->assertSessionHasNoErrors();

    Queue::assertPushed(RefreshRecoveryStatusJob::class, fn (RefreshRecoveryStatusJob $job): bool => ! $job->backUpNow);
});

it('checks the archive of every running database every five minutes', function () {
    $orders = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    $orders->update(['status' => DatabaseStatus::Ready]);
    withRecovery(newDatabase('mysql', 1, DatabaseMode::Single, 'creating'));
    idleDatabase('mariadb', 1, DatabaseMode::Single, 'plain');
    Queue::fake([RefreshRecoveryStatusJob::class]);

    Artisan::call('databases:refresh-recovery');

    Queue::assertPushed(RefreshRecoveryStatusJob::class, 1);
    Queue::assertPushed(RefreshRecoveryStatusJob::class, fn (RefreshRecoveryStatusJob $job): bool => $job->recovery->database_id === $orders->id);
});

it('shows the archive status and the restorable window on the database page without keys', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'), restorable: true);

    $this->get(projectUrl("/databases/{$database->id}"))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.supports_point_in_time_recovery', true)
        ->where('database.data.point_in_time_recovery.health', 'healthy')
        ->where('database.data.point_in_time_recovery.backups_count', 2)
        ->where('database.data.point_in_time_recovery.storage.bucket', 'archives')
        ->where('database.data.point_in_time_recovery.earliest_restorable_at', $database->pointInTimeRecovery?->earliest_restorable_at?->toIso8601String())
        ->where('database.data.point_in_time_recovery.latest_restorable_at', $database->pointInTimeRecovery?->latest_restorable_at?->toIso8601String())
        ->where('storageProviders.0.bucket', 'archives'));

    expect($this->get(projectUrl("/databases/{$database->id}"))->getContent())->not->toContain('EXAMPLEKEY');
});

it('offers no point-in-time recovery for engines without archiving', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);

    $this->get(projectUrl("/databases/{$database->id}"))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.supports_point_in_time_recovery', false)
        ->where('database.data.point_in_time_recovery_unavailable', null)
        ->where('database.data.point_in_time_recovery', null));
});

it('keeps the backups page for a version whose archive tool is not ready yet and says why', function () {
    $database = idleDatabase('mongodb', 1, DatabaseMode::Single, 'nine', '9.0');

    $this->get(projectUrl("/databases/{$database->id}"))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('database.data.supports_point_in_time_recovery', false)
        ->where('database.data.point_in_time_recovery_unavailable', 'Point-in-time recovery is not available for MongoDB 9.0 at this moment.'));
});

it('holds a requested backup until the running operation finishes', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    $database->startOperation('Retune');
    $ssh = fakeSsh();
    $job = (new RefreshRecoveryStatusJob($database->pointInTimeRecovery, backUpNow: true))->withFakeQueueInteractions();

    $job->handle(resolve(RefreshRecoveryStatus::class));

    $job->assertReleased(delay: 30);
    expect($ssh->runs)->toBeEmpty();
});

it('skips a routine archive check while an operation runs', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    $database->startOperation('Retune');
    $job = (new RefreshRecoveryStatusJob($database->pointInTimeRecovery))->withFakeQueueInteractions();

    $job->handle(resolve(RefreshRecoveryStatus::class));

    $job->assertNotReleased();
});

it('keeps a requested backup apart from the routine archive check', function () {
    $recovery = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'))->pointInTimeRecovery;

    expect((new RefreshRecoveryStatusJob($recovery, backUpNow: true))->uniqueId())
        ->not->toBe((new RefreshRecoveryStatusJob($recovery))->uniqueId());
});

it('records a problem when no primary can be asked about the archive', function () {
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'), restorable: true);
    $ssh = fakeSsh();

    resolve(RefreshRecoveryStatus::class)->handle($database->pointInTimeRecovery);

    expect($ssh->runs)->toBeEmpty()
        ->and($database->pointInTimeRecovery?->fresh())
        ->health->toBe(ArchiveHealth::Failing)
        ->problem->toBe('orders has no healthy primary to read the archive from.');
});

it('records a problem when the primary cannot be reached', function () {
    $database = withRecovery(idleDatabase('mysql', 1, DatabaseMode::Single, 'orders'));
    markRoles($database, NodeRole::Primary);
    fakeSsh()->respond('', 255);

    resolve(RefreshRecoveryStatus::class)->handle($database->pointInTimeRecovery);

    expect($database->pointInTimeRecovery?->fresh()?->problem)->toBe("Could not reach {$database->seed()->server->name} to read the archive.");
});

it('leaves the archive alone while the database is busy', function () {
    $database = withRecovery(idleDatabase('postgres', 1, DatabaseMode::Single, 'orders'));
    markRoles($database, NodeRole::Primary);
    $database->startOperation('Upgrade');
    $ssh = fakeSsh();

    (new RefreshRecoveryStatusJob($database->pointInTimeRecovery))->handle(resolve(RefreshRecoveryStatus::class));

    expect($ssh->runs)->toBeEmpty();
});

it('reads the archive status from the primary and backs up on request', function () {
    $database = withRecovery(idleDatabase('postgres', 2));
    markRoles($database, NodeRole::Replica, NodeRole::Primary);
    $primary = $database->nodes()->orderBy('position')->get()[1]->server;
    $ssh = fakeEngine(fakeSsh()->respondToEngine('archive status', ['backups' => 2, 'earliest' => '2026-11-01T00:00:00Z', 'latest' => '2026-11-10T00:00:00Z', 'lastBackup' => '2026-11-09T01:00:00Z', 'problem' => null]));

    resolve(RefreshRecoveryStatus::class)->handle($database->fresh()->pointInTimeRecovery, backUpNow: true);
    $status = collect($ssh->runs)->search(fn (array $run): bool => str_contains($run['script'], '| databasify archive status '));

    expect($ssh->runs[$status]['server'])->toBe($primary->name)
        ->and(json_decode($ssh->engineRequestAt($status), true))->toBe(['backUpNow' => true])
        ->and($database->fresh()->pointInTimeRecovery)->backups_count->toBe(2)->latest_restorable_at->toIso8601ZuluString()->toBe('2026-11-10T00:00:00Z');
});

it('configures every node with the archive when point-in-time recovery is turned on', function () {
    $this->actingAs(User::factory()->create());
    $ssh = fakeEngine()->respondToEngine('archive status', ['backups' => 0, 'earliest' => null, 'latest' => null, 'lastBackup' => null, 'problem' => null]);
    $database = withRecovery(idleDatabase('mysql', 2));
    $configures = fn () => collect($ssh->runs)->keys()->filter(fn (int $index): bool => str_contains($ssh->scriptAt($index), '| databasify node configure '));

    resolve(UpdateRecoverySchedule::class)->handle($database->fresh(), new RecoverySchedule(7, 3));
    advanceFlows();

    expect($configures())->toHaveCount(2)
        ->and(json_decode($ssh->engineRequestAt($configures()->first()), true)['archive'])->retentionDays->toBe(7)->backupHour->toBe(3)
        ->and($database->fresh()->operation)->toBeNull();
});
