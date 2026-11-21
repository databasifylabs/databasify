<?php

declare(strict_types=1);

use App\DTOs\Migrations\SourceReport;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\RunMigrationJob;
use App\Jobs\Databases\StopMigrationJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseMigration;
use App\Models\DatabaseNode;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingDriver;

/**
 * @return array<string, mixed>
 */
function apiMigrationSource(): array
{
    return ['host' => 'old-db.example.com', 'port' => 5432, 'username' => 'migrator', 'password' => 'source-password', 'tls' => true];
}

test('checking a source reports what the engine found', function () {
    RecordingDriver::fake()->on('migration.check', SourceReport::checked('16.4', canReplicate: true, sizeMb: 512, databases: ['shop']));
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/migrations/check", apiMigrationSource())
        ->assertOk()
        ->assertJsonPath('data.reachable', true)
        ->assertJsonPath('data.facts.version', '16.4');
});

test('a migration starts and hides the source password', function () {
    Queue::fake([RunMigrationJob::class]);
    RecordingDriver::fake()->on('migration.check', SourceReport::checked('16.4', canReplicate: true, sizeMb: 512, databases: ['shop']));
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/migrations", apiMigrationSource())
        ->assertAccepted()
        ->assertJsonPath('data.status', 'migrating');

    $listed = $this->getJson("/api/v1/databases/{$database->id}/migrations")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'copying')
        ->assertJsonPath('data.0.source', fn (string $source): bool => str_contains($source, 'old-db.example.com'));

    expect($listed->getContent())->not->toContain('source-password');
});

test('a syncing migration is finished with a cutover', function () {
    Queue::fake([StopMigrationJob::class]);
    $migration = DatabaseMigration::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$migration->database_id}/migrations/{$migration->id}/finish")->assertAccepted();

    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job) => $job->cutover && ! $job->force);
});

test('a migration still copying cannot be cut over', function () {
    $migration = DatabaseMigration::factory()->status(MigrationStatus::Copying)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$migration->database_id}/migrations/{$migration->id}/finish")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['migration' => 'The initial copy is still running, wait until the migration is syncing.']);
});

test('a migration is cancelled with force', function () {
    Queue::fake([StopMigrationJob::class]);
    $migration = DatabaseMigration::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$migration->database_id}/migrations/{$migration->id}", ['force' => true])->assertAccepted();

    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job) => ! $job->cutover && $job->force);
});

test('a migration of another database is not found', function () {
    $database = Database::factory()->create();
    $migration = DatabaseMigration::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/migrations/{$migration->id}/finish")->assertNotFound();
});
