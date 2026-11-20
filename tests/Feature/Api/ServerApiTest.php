<?php

declare(strict_types=1);

use App\Jobs\Servers\ProvisionServerJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Facades\Queue;

test('servers are listed per project', function () {
    $server = Server::factory()->for(Project::factory())->create();
    Server::factory()->for(Project::factory())->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson("/api/v1/projects/{$server->project_id}/servers")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $server->id);
});

test('the server list filters by status', function () {
    $project = Project::factory()->create();
    $ready = Server::factory()->ready()->for($project)->create();
    Server::factory()->for($project)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson('/api/v1/servers?status=ready')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ready->id);
});

test('adding a server queues provisioning and hides the password', function () {
    Queue::fake([ProvisionServerJob::class]);
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $response = $this->postJson("/api/v1/projects/{$project->id}/servers", [
        'name' => 'db-1',
        'public_ip' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'ssh_password' => 'temporary-root-password',
    ]);

    $response->assertAccepted()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonMissingPath('data.ssh_password');
    expect($response->getContent())->not->toContain('temporary-root-password');
    Queue::assertPushed(ProvisionServerJob::class);
});

test('a server shows its database membership', function () {
    $server = Server::factory()->ready()->create();
    $node = DatabaseNode::factory()->for(Database::factory()->create(['name' => 'orders']))->for($server)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson("/api/v1/servers/{$server->id}")
        ->assertOk()
        ->assertJsonPath('data.database.name', 'orders')
        ->assertJsonPath('data.database.node_id', $node->id)
        ->assertJsonStructure(['data' => ['id', 'project_id', 'name', 'status', 'public_ip', 'private_ip', 'network_id', 'network_status', 'server_provider_id', 'region', 'plan', 'ssh_port', 'ssh_user', 'os', 'cpus', 'memory_mb', 'disk_gb', 'host_key_fingerprint', 'created_at']]);
});

test('a server in a database cannot be deleted', function () {
    $server = Server::factory()->ready()->create(['name' => 'db-1']);
    DatabaseNode::factory()->for(Database::factory())->for($server)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->deleteJson("/api/v1/servers/{$server->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['server' => 'db-1 is part of a database, remove it from the database first.']);
});

test('an unused server is deleted', function () {
    $server = Server::factory()->ready()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->deleteJson("/api/v1/servers/{$server->id}")->assertNoContent();

    $this->assertModelMissing($server);
});

test('a server in another project is not found for a limited key', function () {
    $server = Server::factory()->for(Project::factory())->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->limitedTo(Project::factory()->create())->create());

    $this->getJson("/api/v1/servers/{$server->id}")->assertNotFound();
});

test('the app ssh key is shown', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson('/api/v1/servers/ssh-key')
        ->assertOk()
        ->assertJsonPath('data.public_key', fn (string $key): bool => str_starts_with($key, 'ssh-ed25519 '));
});

test('adding a server needs servers:write', function () {
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->postJson("/api/v1/projects/{$project->id}/servers", ['name' => 'db-1'])->assertForbidden();
});
