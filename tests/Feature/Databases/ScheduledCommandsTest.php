<?php

declare(strict_types=1);

use App\DTOs\Databases\EngineImage;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\ApplyMaintenanceJob;
use App\Jobs\Databases\RecordDatabaseMetricsJob;
use App\Jobs\Databases\RefreshDatabaseStatusJob;
use App\Jobs\Databases\RefreshMigrationJob;
use App\Jobs\Databases\RefreshRecoveryStatusJob;
use App\Jobs\Databases\RenewCertificatesJob;
use App\Models\Database;
use App\Models\DatabaseMigration;
use App\Models\DatabaseNode;
use App\Models\PointInTimeRecovery;
use Illuminate\Support\Facades\Queue;

test('maintenance is queued for databases whose weekly window is open', function () {
    Queue::fake([ApplyMaintenanceJob::class]);
    $this->travelTo('2026-11-15 03:30:00');
    $due = Database::factory()->create(['maintenance_day' => 0, 'maintenance_hour' => 3]);
    Database::factory()->create(['maintenance_day' => 0, 'maintenance_hour' => 4]);
    Database::factory()->create(['maintenance_day' => 1, 'maintenance_hour' => 3]);

    $this->artisan('databases:apply-maintenance')->expectsOutputToContain('Queued 1 maintenance runs.')->assertSuccessful();

    Queue::assertPushed(ApplyMaintenanceJob::class, 1);
    Queue::assertPushed(ApplyMaintenanceJob::class, fn (ApplyMaintenanceJob $job) => $job->database->is($due));
});

test('status and metrics are read only for running databases', function (string $command, string $job) {
    Queue::fake([$job]);
    $running = Database::factory()->status(DatabaseStatus::Upgrading)->create();
    Database::factory()->status(DatabaseStatus::Pending)->create();
    Database::factory()->status(DatabaseStatus::Creating)->create();
    Database::factory()->status(DatabaseStatus::Failed)->create();

    $this->artisan($command)->assertSuccessful();

    Queue::assertPushed($job, 1);
    Queue::assertPushed($job, fn ($pushed) => $pushed->database->is($running));
})->with([
    'status' => ['databases:refresh-status', RefreshDatabaseStatusJob::class],
    'metrics' => ['databases:record-metrics', RecordDatabaseMetricsJob::class],
]);

test('only active migrations are checked', function () {
    Queue::fake([RefreshMigrationJob::class]);
    $active = DatabaseMigration::factory()->status(MigrationStatus::Copying)->create();
    DatabaseMigration::factory()->status(MigrationStatus::Completed)->create();

    $this->artisan('databases:refresh-migrations')->assertSuccessful();

    Queue::assertPushed(RefreshMigrationJob::class, 1);
    Queue::assertPushed(RefreshMigrationJob::class, fn (RefreshMigrationJob $job) => $job->migration->is($active));
});

test('archives are checked only for running databases', function () {
    Queue::fake([RefreshRecoveryStatusJob::class]);
    $checked = PointInTimeRecovery::factory()->for(Database::factory())->create();
    PointInTimeRecovery::factory()->for(Database::factory()->status(DatabaseStatus::Failed))->create();

    $this->artisan('databases:refresh-recovery')->assertSuccessful();

    Queue::assertPushed(RefreshRecoveryStatusJob::class, 1);
    Queue::assertPushed(RefreshRecoveryStatusJob::class, fn (RefreshRecoveryStatusJob $job) => $job->recovery->is($checked));
});

test('certificates are renewed only for ready databases that serve without current certificates', function () {
    Queue::fake([RenewCertificatesJob::class]);
    $due = Database::factory()->create();
    DatabaseNode::factory()->for($due)->create();
    $busy = Database::factory()->status(DatabaseStatus::Upgrading)->create();
    DatabaseNode::factory()->for($busy)->create();
    Database::factory()->create();

    $this->artisan('databases:renew-certificates')->expectsOutputToContain('Queued 1 certificate renewals.')->assertSuccessful();

    expect($due->refresh()->status)->toBe(DatabaseStatus::Updating)
        ->and($busy->refresh()->status)->toBe(DatabaseStatus::Upgrading);
    Queue::assertPushed(RenewCertificatesJob::class, fn (RenewCertificatesJob $job) => $job->database->is($due));
});

test('the sdk check lists databases a newer range would make read-only', function () {
    Database::factory()->create(['name' => 'orders']);
    Database::factory()->create(['name' => 'legacy', 'image' => new EngineImage('ghcr.io/databasifylabs/engine-postgres', 'sha256:'.str_repeat('d', 64), '17.4', '0.2.2')]);
    Database::factory()->create(['name' => 'modern', 'image' => new EngineImage('ghcr.io/databasifylabs/engine-postgres', 'sha256:'.str_repeat('e', 64), '18.3', '1.1.0')]);

    $this->artisan('databases:check-sdks', ['--range' => '^1.0'])
        ->expectsOutputToContain('These databases would become read-only under SDK ^1.0:')
        ->expectsOutputToContain('legacy runs SDK 0.2.2')
        ->doesntExpectOutputToContain('modern')
        ->assertSuccessful();
});
