<?php

declare(strict_types=1);

use App\Jobs\Databases\ApplySettingsJob;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Jobs\Databases\DeleteRecoveryBackupsJob;
use App\Jobs\Databases\RefreshRecoveryStatusJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\PointInTimeRecovery;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageProvider;
use Illuminate\Support\Facades\Queue;

test('point-in-time recovery is turned on', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->create();
    $storage = StorageProvider::factory()->for($database->project)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/recovery", ['storage_provider_id' => $storage->id, 'retention_days' => 14, 'backup_hour' => 2])
        ->assertAccepted()
        ->assertJsonPath('data.point_in_time_recovery.storage_provider_id', $storage->id)
        ->assertJsonPath('data.point_in_time_recovery.retention_days', 14);
});

test('a bucket of another project is refused', function () {
    $database = Database::factory()->create();
    $foreign = StorageProvider::factory()->for(Project::factory())->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/recovery", ['storage_provider_id' => $foreign->id, 'retention_days' => 14, 'backup_hour' => 2])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('storage_provider_id');
});

test('the recovery settings are shown', function () {
    $database = Database::factory()->create();
    PointInTimeRecovery::factory()->for($database)->create(['retention_days' => 7]);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/recovery")
        ->assertOk()
        ->assertJsonPath('data.retention_days', 7)
        ->assertJsonStructure(['data' => ['storage_provider_id', 'path', 'retention_days', 'backup_hour', 'health', 'backups_count', 'earliest_restorable_at', 'latest_restorable_at', 'last_backup_at']]);
});

test('a database without recovery has no recovery settings', function () {
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/recovery")->assertNotFound();
});

test('a backup is taken now', function () {
    Queue::fake([RefreshRecoveryStatusJob::class]);
    $database = Database::factory()->create();
    PointInTimeRecovery::factory()->for($database)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/recovery/backup")->assertAccepted();

    Queue::assertPushed(RefreshRecoveryStatusJob::class, fn (RefreshRecoveryStatusJob $job) => $job->backUpNow);
});

test('turning recovery off can delete the backups', function () {
    Queue::fake([ApplySettingsJob::class, DeleteRecoveryBackupsJob::class]);
    $database = Database::factory()->create();
    $recovery = PointInTimeRecovery::factory()->for($database)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$database->id}/recovery", ['delete_backups' => true])
        ->assertAccepted()
        ->assertJsonPath('data.point_in_time_recovery', null);

    $this->assertModelMissing($recovery);
    Queue::assertPushed(DeleteRecoveryBackupsJob::class);
});

test('a restore returns the new database', function () {
    $this->freezeTime();
    Queue::fake([CreateDatabaseJob::class]);
    $source = Database::factory()->create(['name' => 'orders']);
    PointInTimeRecovery::factory()->restorable()->for($source)->create();
    $server = Server::factory()->ready()->for($source->project)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $response = $this->postJson("/api/v1/databases/{$source->id}/recovery/restore", ['name' => 'orders-restored', 'server_id' => $server->id, 'target' => now()->subDay()->utc()->startOfSecond()->toIso8601String()])
        ->assertAccepted()
        ->assertJsonPath('data.name', 'orders-restored')
        ->assertJsonPath('data.restored_from.database', 'orders');

    expect($response->json('data.id'))->not->toBe($source->id);
});
