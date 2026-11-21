<?php

declare(strict_types=1);

use App\Contracts\Images\ImageRegistry;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Jobs\Databases\DeleteDatabaseJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImageRegistry;

test('databases are listed per project', function () {
    $database = Database::factory()->for(Project::factory())->create();
    Database::factory()->for(Project::factory())->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/projects/{$database->project_id}/databases")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $database->id);
});

test('the database list filters by engine', function () {
    $project = Project::factory()->create();
    Database::factory()->for($project)->create(['name' => 'orders']);
    Database::factory()->for($project)->engine('mysql', '8.4')->create(['name' => 'shop']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson('/api/v1/databases?engine=mysql')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'shop');
});

test('creating a database queues its setup', function () {
    Queue::fake([CreateDatabaseJob::class]);
    app()->instance(ImageRegistry::class, new FakeImageRegistry(['18.1' => '0.2.2']));
    $server = Server::factory()->ready()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/projects/{$server->project_id}/databases", ['name' => 'orders', 'engine' => 'postgres', 'version_line' => '18', 'primary_server_id' => $server->id])
        ->assertAccepted()
        ->assertJsonPath('data.name', 'orders')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.project_id', $server->project_id);

    Queue::assertPushed(CreateDatabaseJob::class);
});

test('a database shows its nodes and no passwords', function () {
    $database = Database::factory()->create();
    $database->users()->create(['name' => 'app', 'password' => 'never-in-a-response']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $response = $this->getJson("/api/v1/databases/{$database->id}")
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'project_id', 'name', 'engine', 'version_line', 'engine_version', 'mode', 'status', 'health', 'port', 'hostnames', 'persistence', 'maintenance', 'nodes', 'point_in_time_recovery', 'created_at']])
        ->assertJsonMissingPath('data.users');

    expect($response->getContent())->not->toContain('never-in-a-response');
});

test('deleting a database passes its flags', function () {
    Queue::fake([DeleteDatabaseJob::class]);
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$database->id}", ['erase_data' => true, 'delete_backups' => true])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'deleting');

    Queue::assertPushed(DeleteDatabaseJob::class, fn (DeleteDatabaseJob $job) => $job->eraseData && $job->deleteBackups && ! $job->force);
});

test('a busy database is not deleted', function () {
    $database = Database::factory()->status(DatabaseStatus::Upgrading)->create(['name' => 'orders']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$database->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['database' => 'orders is busy upgrading, try again when it finishes.']);
});

test('a database in another project is not found for a limited key', function () {
    $database = Database::factory()->for(Project::factory())->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->limitedTo(Project::factory()->create())->create());

    $this->getJson("/api/v1/databases/{$database->id}")->assertNotFound();
});

test('engines list the catalog', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson('/api/v1/databases/engines')
        ->assertOk()
        ->assertJsonFragment(['value' => 'postgres']);
});

test('creating a database needs databases:write', function () {
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->postJson("/api/v1/projects/{$project->id}/databases", ['name' => 'orders'])->assertForbidden();
});
