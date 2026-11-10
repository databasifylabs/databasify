<?php

declare(strict_types=1);

use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\RunMigrationJob;
use App\Jobs\Databases\StopMigrationJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->ssh = fakeEngine();
});

it('starts a continuous migration with tls on by default', function () {
    $database = migratingDatabase('mysql');
    Queue::fake([RunMigrationJob::class, StopMigrationJob::class]);
    $this->ssh->respond(mysqlSourceReport());

    $this->from(projectUrl("/databases/{$database->id}"))
        ->post(projectUrl("/databases/{$database->id}/migrations"), ['host' => 'db.example.com', 'port' => 3306, 'username' => 'migrator', 'password' => 'secret', 'databases' => ['shop']])
        ->assertRedirect(projectUrl("/databases/{$database->id}"))
        ->assertSessionHasNoErrors();

    $migration = $database->migrations()->sole();
    expect($migration)->mode->toBe(MigrationMode::Continuous)->status->toBe(MigrationStatus::Copying)->database_node_id->toBe($database->seed()->id)
        ->and($migration->source)->toEqual(new MigrationSource('db.example.com', 3306, 'migrator', 'secret', true, ['shop']))
        ->and($database->fresh()->operation)->toBe('Migrate from db.example.com:3306');
    Queue::assertPushed(RunMigrationJob::class, fn (RunMigrationJob $job): bool => $job->migration->is($migration));
});

it('rejects malformed sources before contacting them', function (string $engine, array $payload, array $errors) {
    $database = migratingDatabase($engine);
    Queue::fake([RunMigrationJob::class, StopMigrationJob::class]);
    $ssh = $this->ssh;

    $this->post(projectUrl("/databases/{$database->id}/migrations"), $payload)->assertSessionHasErrors($errors);

    expect($ssh->runs)->toBe([])
        ->and($database->migrations()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'mysql system schema' => ['mysql', ['host' => 'db.example.com', 'port' => 3306, 'username' => 'm', 'password' => 's', 'databases' => ['mysql']], ['databases.0']],
    'mariadb missing credentials' => ['mariadb', ['host' => 'db.example.com', 'port' => 3306], ['username', 'password']],
    'postgres template database' => ['postgres', ['host' => 'pg.example.com', 'port' => 5432, 'username' => 'm', 'password' => 's', 'databases' => ['template1']], ['databases.0']],
    'postgres odd database name' => ['postgres', ['host' => 'pg.example.com', 'port' => 5432, 'username' => 'm', 'password' => 's', 'databases' => ['shop; drop']], ['databases.0']],
    'database names the target cannot record' => ['mysql', ['host' => 'db.example.com', 'port' => 3306, 'username' => 'm', 'password' => 's', 'databases' => ['shop', 'my-app', '2024', 'a$b']], ['databases.1', 'databases.2', 'databases.3']],
    'valkey databases' => ['valkey', ['host' => 'cache.example.com', 'port' => 6379, 'databases' => ['0']], ['databases']],
    'redis bad port and mode' => ['redis', ['host' => 'cache.example.com', 'port' => 70000, 'mode' => 'forever'], ['port', 'mode']],
    'host with spaces' => ['mysql', ['host' => 'db example.com', 'port' => 3306, 'username' => 'm', 'password' => 's'], ['host']],
    'username with quotes' => ['mysql', ['host' => 'db.example.com', 'port' => 3306, 'username' => "m'x", 'password' => 's', 'tls' => 'maybe'], ['username', 'tls']],
]);
it('finishes a migration only once it is syncing', function () {
    $database = migratingDatabase('postgres');
    Queue::fake([RunMigrationJob::class, StopMigrationJob::class]);
    $copying = migrationOf($database, MigrationMode::Continuous);

    $this->post(projectUrl("/databases/{$database->id}/migrations/{$copying->id}/finish"))->assertSessionHasErrors(['migration' => 'The initial copy is still running, wait until the migration is syncing.']);
    Queue::assertNothingPushed();

    $copying->update(['status' => MigrationStatus::Syncing]);

    $this->from(projectUrl("/databases/{$database->id}"))->post(projectUrl("/databases/{$database->id}/migrations/{$copying->id}/finish"))->assertRedirect(projectUrl("/databases/{$database->id}"))->assertSessionHasNoErrors();
    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job): bool => $job->cutover && ! $job->force);
    expect($database->fresh()->operation)->toBe('Finish migration');
});

it('stops or force stops an active migration', function () {
    $database = migratingDatabase('valkey');
    Queue::fake([RunMigrationJob::class, StopMigrationJob::class]);
    $migration = migrationOf($database, MigrationMode::OneTime);

    $this->delete(projectUrl("/databases/{$database->id}/migrations/{$migration->id}"))->assertSessionHasNoErrors();
    expect($database->fresh()->operation)->toBe('Stop migration');
    $database->finishOperation();
    $this->delete(projectUrl("/databases/{$database->id}/migrations/{$migration->id}"), ['force' => true])->assertSessionHasNoErrors();

    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job): bool => ! $job->cutover && ! $job->force);
    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job): bool => ! $job->cutover && $job->force);
});

it('refuses to stop or finish a migration that already ended', function () {
    $database = migratingDatabase('mariadb');
    Queue::fake([RunMigrationJob::class, StopMigrationJob::class]);
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Completed);

    $this->delete(projectUrl("/databases/{$database->id}/migrations/{$migration->id}"))->assertSessionHasErrors(['migration' => 'This migration has already ended.']);
    $this->post(projectUrl("/databases/{$database->id}/migrations/{$migration->id}/finish"))->assertSessionHasErrors('migration');
    Queue::assertNothingPushed();
});

it('force stops a migration that already ended to clean it up again', function () {
    $database = migratingDatabase('mysql');
    Queue::fake([RunMigrationJob::class, StopMigrationJob::class]);
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Stopped);
    $migration->update(['cleanup_failed_at' => now()]);

    $this->from(projectUrl("/databases/{$database->id}"))->delete(projectUrl("/databases/{$database->id}/migrations/{$migration->id}"), ['force' => true])
        ->assertRedirect(projectUrl("/databases/{$database->id}"))
        ->assertSessionHasNoErrors();

    Queue::assertPushed(StopMigrationJob::class, fn (StopMigrationJob $job): bool => $job->migration->is($migration) && ! $job->cutover && $job->force);
    expect($database->fresh()->operation)->toBe('Stop migration');
});

it('does not find a migration through another database', function () {
    $database = migratingDatabase('mysql');
    $other = migratingDatabase('postgres');
    Queue::fake([RunMigrationJob::class, StopMigrationJob::class]);
    $migration = migrationOf($other, MigrationMode::Continuous, MigrationStatus::Syncing);

    $this->post(projectUrl("/databases/{$database->id}/migrations/{$migration->id}/finish"))->assertNotFound();
    $this->delete(projectUrl("/databases/{$database->id}/migrations/{$migration->id}"))->assertNotFound();
    Queue::assertNothingPushed();
});
