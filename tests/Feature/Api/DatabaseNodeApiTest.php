<?php

declare(strict_types=1);

use App\DTOs\Databases\NodeStatus;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\AddNodeJob;
use App\Jobs\Databases\RebootNodeJob;
use App\Jobs\Databases\RemoveNodeJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingDriver;

test('the nodes of a database are listed', function () {
    $node = DatabaseNode::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$node->database_id}/nodes")
        ->assertOk()
        ->assertJsonPath('data.0.id', $node->id);
});

test('a standby is added on the same private network', function () {
    Queue::fake([AddNodeJob::class]);
    $network = Network::factory()->create(['cidr' => '10.10.0.0/16']);
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->for(Server::factory()->ready()->onNetwork($network))->create();
    $server = Server::factory()->ready()->onNetwork($network)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$database->id}/nodes", ['server_id' => $server->id])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'scaling')
        ->assertJsonCount(2, 'data.nodes');
});

test('a read-only node is renamed', function () {
    $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->putJson("/api/v1/databases/{$readOnly->database_id}/nodes/{$readOnly->id}", ['name' => 'orders-analytics'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'orders-analytics');
});

test('a read-only node is rebooted on its own', function () {
    Queue::fake([RebootNodeJob::class]);
    $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$readOnly->database_id}/nodes/{$readOnly->id}/reboot")
        ->assertAccepted()
        ->assertJsonPath('data.status', 'rebooting');
});

test('a member node is not rebooted on its own', function () {
    $member = DatabaseNode::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->postJson("/api/v1/databases/{$member->database_id}/nodes/{$member->id}/reboot")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['database' => 'Only read-only nodes have their own trusted sources, config and pools.']);
});

test('the only member cannot be removed', function () {
    Queue::fake([RemoveNodeJob::class]);
    $node = DatabaseNode::factory()->create();
    RecordingDriver::fake()->on('nodes.status', new NodeStatus(NodeRole::Primary, '18.1'));
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->deleteJson("/api/v1/databases/{$node->database_id}/nodes/{$node->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['node' => "{$node->server->name} is the only member left."]);
});

test('a node of another database is not found', function () {
    $database = Database::factory()->create();
    $foreignNode = DatabaseNode::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson("/api/v1/databases/{$database->id}/nodes/{$foreignNode->id}")->assertNotFound();
});

test('nested routes of a database in another project are not found for a limited key', function () {
    $node = DatabaseNode::factory()->for(Database::factory()->for(Project::factory()))->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->limitedTo(Project::factory()->create())->create());

    $this->getJson("/api/v1/databases/{$node->database_id}/nodes")->assertNotFound();
});
