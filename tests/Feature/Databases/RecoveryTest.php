<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplySettingsJob;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Jobs\Databases\DeleteRecoveryBackupsJob;
use App\Jobs\Databases\RefreshRecoveryStatusJob;
use App\Models\Database;
use App\Models\PointInTimeRecovery;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageProvider;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('point-in-time recovery can be turned on', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->create();
    $storage = StorageProvider::factory()->for($database->project)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.recovery.store', [$database->project_id, $database]), ['storage_provider_id' => $storage->id, 'retention_days' => 14, 'backup_hour' => 2])
        ->assertSessionHasNoErrors();

    expect($database->refresh()->status)->toBe(DatabaseStatus::Updating)
        ->and($database->pointInTimeRecovery)
        ->storage_provider_id->toBe($storage->id)
        ->retention_days->toBe(14)
        ->backup_hour->toBe(2);
    Queue::assertPushed(ApplySettingsJob::class, fn (ApplySettingsJob $job) => $job->archiveChanged);
});

test('recovery retention is limited and the bucket must be in the project', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->create();
    $foreign = StorageProvider::factory()->for(Project::factory())->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.recovery.store', [$database->project_id, $database]), ['storage_provider_id' => $foreign->id, 'retention_days' => 36, 'backup_hour' => 2])
        ->assertSessionHasErrors([
            'storage_provider_id' => 'The selected storage provider id is invalid.',
            'retention_days' => 'The retention days field must be between 1 and 35.',
        ]);

    expect($database->pointInTimeRecovery()->exists())->toBeFalse();
    Queue::assertNotPushed(ApplySettingsJob::class);
});

test('recovery cannot be turned on twice', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->create(['name' => 'orders']);
    PointInTimeRecovery::factory()->for($database)->create();
    $storage = StorageProvider::factory()->for($database->project)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.recovery.store', [$database->project_id, $database]), ['storage_provider_id' => $storage->id, 'retention_days' => 7, 'backup_hour' => 2])
        ->assertSessionHasErrors(['recovery' => 'orders already has point-in-time recovery.']);

    Queue::assertNotPushed(ApplySettingsJob::class);
});

test('the recovery schedule can be changed', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->create();
    $recovery = PointInTimeRecovery::factory()->for($database)->create();

    $this->actingAs(User::factory()->create())
        ->put(route('databases.recovery.update', [$database->project_id, $database]), ['retention_days' => 30, 'backup_hour' => 5])
        ->assertSessionHasNoErrors();

    expect($recovery->refresh())
        ->retention_days->toBe(30)
        ->backup_hour->toBe(5);
    Queue::assertPushed(ApplySettingsJob::class);
});

test('turning recovery off can delete the backups later', function () {
    Queue::fake([ApplySettingsJob::class, DeleteRecoveryBackupsJob::class]);
    $database = Database::factory()->create();
    $recovery = PointInTimeRecovery::factory()->for($database)->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('databases.recovery.destroy', [$database->project_id, $database]), ['delete_backups' => true])
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($recovery);
    Queue::assertPushed(ApplySettingsJob::class);
    Queue::assertPushed(DeleteRecoveryBackupsJob::class, fn (DeleteRecoveryBackupsJob $job) => $job->archive->path === $recovery->path);
});

test('turning recovery off keeps the backups by default', function () {
    Queue::fake([ApplySettingsJob::class, DeleteRecoveryBackupsJob::class]);
    $database = Database::factory()->create();
    PointInTimeRecovery::factory()->for($database)->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('databases.recovery.destroy', [$database->project_id, $database]))
        ->assertSessionHasNoErrors();

    Queue::assertNotPushed(DeleteRecoveryBackupsJob::class);
});

test('a backup can be taken now', function () {
    Queue::fake([RefreshRecoveryStatusJob::class]);
    $database = Database::factory()->create();
    $recovery = PointInTimeRecovery::factory()->for($database)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.recovery.backup', [$database->project_id, $database]))
        ->assertSessionHasNoErrors();

    Queue::assertPushed(RefreshRecoveryStatusJob::class, fn (RefreshRecoveryStatusJob $job) => $job->recovery->is($recovery) && $job->backUpNow);
});

test('backing up needs recovery turned on', function () {
    Queue::fake([RefreshRecoveryStatusJob::class]);
    $database = Database::factory()->create(['name' => 'orders']);

    $this->actingAs(User::factory()->create())
        ->post(route('databases.recovery.check', [$database->project_id, $database]))
        ->assertSessionHasErrors(['recovery' => 'orders has no point-in-time recovery, turn it on first.']);

    Queue::assertNotPushed(RefreshRecoveryStatusJob::class);
});

test('a database can be restored to a point in time as a new database', function () {
    $this->freezeTime();
    Queue::fake([CreateDatabaseJob::class]);
    $source = Database::factory()->create(['name' => 'orders']);
    $source->users()->create(['name' => 'app', 'password' => 'secret']);
    PointInTimeRecovery::factory()->restorable()->for($source)->create();
    $server = Server::factory()->ready()->for($source->project)->create();
    $target = now()->subDay()->utc()->startOfSecond();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.recovery.restore', [$source->project_id, $source]), ['name' => 'orders-restored', 'server_id' => $server->id, 'target' => $target->toIso8601String()])
        ->assertSessionHasNoErrors();

    $restored = Database::query()->where('name', 'orders-restored')->sole();
    expect($restored->settings->restore)
        ->database->toBe('orders')
        ->and($restored->settings->restore->target->equalTo($target))->toBeTrue()
        ->and($restored->image->release)->toBe($source->image->release)
        ->and($restored->users()->pluck('name')->all())->toBe(['app'])
        ->and($restored->pointInTimeRecovery)->toBeNull();
    Queue::assertPushed(CreateDatabaseJob::class);
});

test('a restored mongodb database gets its own point-in-time recovery to restore through', function () {
    $this->freezeTime();
    Queue::fake([CreateDatabaseJob::class]);
    $source = Database::factory()->engine('mongodb', '8.0')->create(['name' => 'events']);
    $archive = PointInTimeRecovery::factory()->restorable()->for($source)->create();
    $server = Server::factory()->ready()->for($source->project)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.recovery.restore', [$source->project_id, $source]), ['name' => 'events-restored', 'server_id' => $server->id, 'target' => now()->subDay()->toIso8601String()])
        ->assertSessionHasNoErrors();

    expect(Database::query()->where('name', 'events-restored')->sole()->pointInTimeRecovery)
        ->storage_provider_id->toBe($archive->storage_provider_id)
        ->path->not->toBe($archive->path);
});

test('a point outside the restorable window is refused', function () {
    $this->travelTo('2026-11-15 12:00:00');
    Queue::fake([CreateDatabaseJob::class]);
    $source = Database::factory()->create(['name' => 'orders']);
    PointInTimeRecovery::factory()->restorable()->for($source)->create();
    $server = Server::factory()->ready()->for($source->project)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.recovery.restore', [$source->project_id, $source]), ['name' => 'orders-restored', 'server_id' => $server->id, 'target' => '2026-11-10 00:00:00'])
        ->assertSessionHasErrors(['target' => 'orders cannot be restored to 2026-11-10 00:00:00 UTC. Pick a time between 2026-11-13 12:00:00 and 2026-11-15 11:59:00 UTC.']);

    expect(Database::query()->where('name', 'orders-restored')->exists())->toBeFalse();
    Queue::assertNotPushed(CreateDatabaseJob::class);
});
