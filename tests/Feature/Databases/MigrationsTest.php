<?php

declare(strict_types=1);

use App\DTOs\Migrations\SourceReport;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\RunMigrationJob;
use App\Jobs\Databases\StopMigrationJob;
use App\Models\Database;
use App\Models\DatabaseMigration;
use App\Models\DatabaseNode;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingDriver;

function sourceForm(array $overrides = []): array
{
    return ['host' => 'old-db.example.com', 'port' => 5432, 'username' => 'migrator', 'password' => 'secret', 'tls' => true, ...$overrides];
}

function databaseToMigrateInto(): Database
{
    $database = Database::factory()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->create();

    return $database;
}

test('checking a source reports what the engine found', function () {
    RecordingDriver::fake()->on('migration.check', SourceReport::checked('16.4', canReplicate: true, sizeMb: 512, databases: ['shop'], warnings: ['Large objects are not copied.']));
    $database = databaseToMigrateInto();

    $this->actingAs(User::factory()->create())
        ->postJson(route('databases.migrations.check', [$database->project_id, $database]), sourceForm())
        ->assertOk()
        ->assertJsonPath('reachable', true)
        ->assertJsonPath('continuous', true)
        ->assertJsonPath('warnings', ['Large objects are not copied.'])
        ->assertJsonPath('facts.version', '16.4');
});

test('source hosts cannot carry shell characters', function () {
    $engine = RecordingDriver::fake();
    $database = databaseToMigrateInto();

    $this->actingAs(User::factory()->create())
        ->postJson(route('databases.migrations.check', [$database->project_id, $database]), sourceForm(['host' => 'db.example.com;reboot']))
        ->assertJsonValidationErrors(['host' => 'The host field format is invalid.']);

    expect($engine->called('migration.check'))->toBeFalse();
});

test('a migration starts with the databases the source offers', function () {
    Queue::fake([RunMigrationJob::class]);
    RecordingDriver::fake()->on('migration.check', SourceReport::checked('16.4', canReplicate: true, sizeMb: 512, databases: ['shop', 'billing']));
    $database = databaseToMigrateInto();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.migrations.store', [$database->project_id, $database]), sourceForm())
        ->assertSessionHasNoErrors();

    $migration = $database->migrations()->sole();
    expect($migration)
        ->status->toBe(MigrationStatus::Copying)
        ->mode->toBe(MigrationMode::Continuous)
        ->source->discovered->toBe(['shop', 'billing'])
        ->and($database->refresh()->status)->toBe(DatabaseStatus::Migrating);
    Queue::assertPushed(RunMigrationJob::class, fn (RunMigrationJob $job) => $job->migration->is($migration));
});

test('a migration is refused', function (SourceReport $report, array $form, array $errors) {
    Queue::fake([RunMigrationJob::class]);
    RecordingDriver::fake()->on('migration.check', $report);
    $database = databaseToMigrateInto();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.migrations.store', [$database->project_id, $database]), sourceForm($form))
        ->assertSessionHasErrors($errors);

    expect($database->migrations()->exists())->toBeFalse()
        ->and($database->refresh()->status)->toBe(DatabaseStatus::Ready);
    Queue::assertNotPushed(RunMigrationJob::class);
})->with([
    'unreachable source' => [SourceReport::unreachable('connection refused'), [], ['source' => 'Could not connect to the source: connection refused']],
    'target already holds rows' => [SourceReport::checked('16.4', true, 10, ['shop'], occupied: ['shop']), [], ['source']],
    'source cannot replicate' => [SourceReport::checked('16.4', false, 10, ['shop']), ['mode' => 'continuous'], ['mode']],
]);

test('a one-time copy works without replication', function () {
    Queue::fake([RunMigrationJob::class]);
    RecordingDriver::fake()->on('migration.check', SourceReport::checked('16.4', canReplicate: false, sizeMb: 10, databases: ['shop']));
    $database = databaseToMigrateInto();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.migrations.store', [$database->project_id, $database]), sourceForm(['mode' => 'one_time']))
        ->assertSessionHasNoErrors();

    expect($database->migrations()->sole()->mode)->toBe(MigrationMode::OneTime);
});

test('a syncing migration can be finished with a cutover', function () {
    Queue::fake([StopMigrationJob::class]);
    $migration = DatabaseMigration::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.migrations.finish', [$migration->database->project_id, $migration->database, $migration]))
        ->assertSessionHasNoErrors();

    expect($migration->database->refresh()->status)->toBe(DatabaseStatus::Migrating);
    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job) => $job->cutover && ! $job->force);
});

test('a migration still copying cannot be cut over', function () {
    Queue::fake([StopMigrationJob::class]);
    $migration = DatabaseMigration::factory()->status(MigrationStatus::Copying)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.migrations.finish', [$migration->database->project_id, $migration->database, $migration]))
        ->assertSessionHasErrors(['migration' => 'The initial copy is still running, wait until the migration is syncing.']);

    Queue::assertNotPushed(StopMigrationJob::class);
});

test('an ended migration cannot be stopped again unless its cleanup failed', function () {
    Queue::fake([StopMigrationJob::class]);
    $ended = DatabaseMigration::factory()->status(MigrationStatus::Completed)->create();
    $leftover = DatabaseMigration::factory()->cleanupFailed()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('databases.migrations.destroy', [$ended->database->project_id, $ended->database, $ended]), ['force' => true])
        ->assertSessionHasErrors(['migration' => 'This migration has already ended.']);
    $this->actingAs($user)
        ->delete(route('databases.migrations.destroy', [$leftover->database->project_id, $leftover->database, $leftover]), ['force' => true])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(StopMigrationJob::class, 1);
    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job) => $job->migration->is($leftover) && $job->force && ! $job->cutover);
});
