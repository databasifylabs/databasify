<?php

declare(strict_types=1);

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Databases\EngineImage;
use App\DTOs\Databases\NodeStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\AddNodeJob;
use App\Jobs\Databases\CreateDatabaseJob;
use App\Jobs\Databases\DeleteDatabaseJob;
use App\Jobs\Databases\FailoverDatabaseJob;
use App\Jobs\Databases\RebootDatabaseJob;
use App\Jobs\Databases\RebootNodeJob;
use App\Jobs\Databases\RemoveNodeJob;
use App\Jobs\Databases\RetuneDatabaseJob;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerLog;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImageRegistry;
use Tests\Support\RecordingDriver;

function actingAsDatabaseKey(string $level = 'write'): void
{
    actingAsApiKey(ApiKey::factory()->withScopes("databases:{$level}")->create());
}

describe('reading databases', function () {
    test('a project lists only its own databases', function () {
        $database = Database::factory()->for(Project::factory())->create();
        Database::factory()->for(Project::factory())->create();
        actingAsDatabaseKey('read');

        $response = mcpCall('list_databases', ['project' => $database->project_id]);

        expect(array_column(mcpData($response)['data'], 'id'))->toBe([$database->id]);
    });

    test('the database list filters by engine', function () {
        $project = Project::factory()->create();
        Database::factory()->for($project)->create(['name' => 'orders']);
        Database::factory()->for($project)->engine('mysql', '8.4')->create(['name' => 'shop']);
        actingAsDatabaseKey('read');

        $response = mcpCall('list_databases', ['engine' => 'mysql']);

        expect(array_column(mcpData($response)['data'], 'name'))->toBe(['shop']);
    });

    test('the engine catalog lists postgres', function () {
        actingAsDatabaseKey('read');

        $response = mcpCall('list_database_engines');

        expect(array_column(mcpData($response), 'value'))->toContain('postgres');
    });

    test('a database shows its nodes and never a password', function () {
        $database = Database::factory()->create();
        $database->users()->create(['name' => 'app', 'password' => 'never-in-a-response']);
        actingAsDatabaseKey('read');

        $response = mcpCall('get_database', ['database' => $database->id]);

        expect(mcpData($response))->toHaveKeys(['nodes', 'hostnames', 'point_in_time_recovery'])->not->toHaveKey('users')
            ->and(json_encode($response))->not->toContain('never-in-a-response');
    });

    test('a database in another project is not found for a limited key', function () {
        $database = Database::factory()->for(Project::factory())->create();
        actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->limitedTo(Project::factory()->create())->create());

        $response = mcpCall('get_database', ['database' => $database->id]);

        expect(mcpError($response))->toContain('No database with this id.');
    });
});

describe('database lifecycle', function () {
    test('creating a database queues its setup', function () {
        Queue::fake([CreateDatabaseJob::class]);
        app()->instance(ImageRegistry::class, new FakeImageRegistry(['18.1' => '0.2.2']));
        $server = Server::factory()->ready()->create();
        actingAsDatabaseKey();

        $response = mcpCall('create_database', ['project' => $server->project_id, 'name' => 'orders', 'engine' => 'postgres', 'version_line' => '18', 'primary_server_id' => $server->id]);

        expect(mcpData($response))->toMatchArray(['name' => 'orders', 'status' => 'pending', 'project_id' => $server->project_id]);
        $this->assertDatabaseHas('databases', ['project_id' => $server->project_id, 'name' => 'orders', 'status' => DatabaseStatus::Pending->value]);
        Queue::assertPushed(CreateDatabaseJob::class);
    });

    test('deleting a database passes its flags to the job', function () {
        Queue::fake([DeleteDatabaseJob::class]);
        $database = Database::factory()->create();
        actingAsDatabaseKey();

        $response = mcpCall('delete_database', ['database' => $database->id, 'erase_data' => true, 'delete_backups' => true]);

        expect(mcpData($response)['status'])->toBe('deleting');
        Queue::assertPushed(DeleteDatabaseJob::class, fn (DeleteDatabaseJob $job) => $job->eraseData && $job->deleteBackups && ! $job->force);
    });

    test('a busy database is not deleted', function () {
        Queue::fake([DeleteDatabaseJob::class]);
        $database = Database::factory()->status(DatabaseStatus::Upgrading)->create(['name' => 'orders']);
        actingAsDatabaseKey();

        $response = mcpCall('delete_database', ['database' => $database->id]);

        expect(mcpError($response))->toContain('orders is busy upgrading, try again when it finishes.');
        Queue::assertNotPushed(DeleteDatabaseJob::class);
    });

    test('a database with a standby fails over', function () {
        Queue::fake([FailoverDatabaseJob::class]);
        $database = Database::factory()->highlyAvailable()->create();
        DatabaseNode::factory()->for($database)->create();
        DatabaseNode::factory()->for($database)->replica()->create();
        actingAsDatabaseKey();

        $response = mcpCall('failover_database', ['database' => $database->id]);

        expect(mcpData($response)['status'])->toBe('recovering');
        Queue::assertPushed(FailoverDatabaseJob::class);
    });

    test('failover without a standby is refused', function () {
        Queue::fake([FailoverDatabaseJob::class]);
        $database = Database::factory()->create();
        DatabaseNode::factory()->for($database)->create();
        actingAsDatabaseKey();

        $response = mcpCall('failover_database', ['database' => $database->id]);

        expect(mcpError($response))->toContain('Failover needs at least one standby node.');
        Queue::assertNotPushed(FailoverDatabaseJob::class);
    });

    test('checking for an upgrade records the newer version', function () {
        app()->instance(ImageRegistry::class, new FakeImageRegistry(['18.2' => '0.2.2', '18.1' => '0.2.2']));
        $database = Database::factory()->create();
        actingAsDatabaseKey();

        $response = mcpCall('check_database_upgrade', ['database' => $database->id]);

        expect(mcpData($response)['available_version'])->toBe('18.2')
            ->and($database->refresh()->available_image?->release)->toBe('18.2');
    });

    test('a database upgrades to its available image', function () {
        Queue::fake([UpgradeDatabaseJob::class]);
        $newer = new EngineImage('ghcr.io/databasifylabs/engine-postgres', 'sha256:'.str_repeat('b', 64), '18.2', '0.2.2');
        $database = Database::factory()->create(['available_image' => $newer]);
        actingAsDatabaseKey();

        $response = mcpCall('upgrade_database', ['database' => $database->id]);

        expect(mcpData($response))->toMatchArray(['status' => 'upgrading', 'engine_version' => '18.2']);
        Queue::assertPushed(UpgradeDatabaseJob::class);
    });

    test('a database is retuned', function () {
        Queue::fake([RetuneDatabaseJob::class]);
        $database = Database::factory()->create();
        actingAsDatabaseKey();

        $response = mcpCall('retune_database', ['database' => $database->id]);

        expect(mcpData($response)['status'])->toBe('updating');
        Queue::assertPushed(RetuneDatabaseJob::class);
    });

    test('a healthy database is rebooted', function () {
        Queue::fake([RebootDatabaseJob::class]);
        $database = Database::factory()->highlyAvailable()->create();
        DatabaseNode::factory()->for($database)->create(['position' => 1, 'role' => NodeRole::Primary]);
        DatabaseNode::factory()->for($database)->create(['position' => 2, 'role' => NodeRole::Replica]);
        RecordingDriver::fake()->on('nodes.status', fn (DatabaseNode $node) => new NodeStatus($node->role, '18.1'));
        actingAsDatabaseKey();

        $response = mcpCall('reboot_database', ['database' => $database->id]);

        expect(mcpData($response)['status'])->toBe('rebooting');
        Queue::assertPushed(RebootDatabaseJob::class);
    });

    test('recovery is refused when no member answers', function () {
        $database = Database::factory()->highlyAvailable()->create(['name' => 'orders']);
        DatabaseNode::factory()->for($database)->create(['position' => 1, 'role' => NodeRole::Primary]);
        DatabaseNode::factory()->for($database)->create(['position' => 2, 'role' => NodeRole::Replica]);
        RecordingDriver::fake()->on('nodes.status', fn () => new NodeStatus(NodeRole::Unknown, '18.1'));
        actingAsDatabaseKey();

        $response = mcpCall('recover_database', ['database' => $database->id, 'others_down' => true]);

        expect(mcpError($response))->toContain('orders has no reachable member to recover from.')
            ->and($database->refresh()->status)->not->toBe(DatabaseStatus::Recovering);
    });

    test('a failed setup is retried', function () {
        Queue::fake([CreateDatabaseJob::class]);
        $database = Database::factory()->status(DatabaseStatus::Failed)->create();
        actingAsDatabaseKey();

        $response = mcpCall('retry_database_setup', ['database' => $database->id]);

        expect(mcpData($response)['status'])->toBe('pending');
        Queue::assertPushed(CreateDatabaseJob::class);
    });

    test('refreshing reads the node versions now', function () {
        $database = Database::factory()->create();
        DatabaseNode::factory()->for($database)->create(['position' => 1, 'role' => NodeRole::Primary]);
        RecordingDriver::fake()->on('nodes.status', fn () => new NodeStatus(NodeRole::Primary, '18.1'));
        actingAsDatabaseKey();

        $response = mcpCall('refresh_database', ['database' => $database->id]);

        expect(mcpData($response)['nodes'][0]['engine_version'])->toBe('18.1');
    });

    test('stopping a database cancels its running engine task', function () {
        $database = Database::factory()->status(DatabaseStatus::Scaling)->create();
        $node = DatabaseNode::factory()->for($database)->create(['task_key' => 'run.node.copy']);
        $this->ssh()->replyTo('databasify task stop', '{"key":"run.node.copy","kind":"copy","state":"cancelled","phase":"stopped"}');
        actingAsDatabaseKey();

        $response = mcpCall('stop_database_operation', ['database' => $database->id]);

        expect(mcpData($response)['id'])->toBe($database->id)
            ->and($node->refresh()->task_key)->toBeNull();
    });
});

describe('database nodes', function () {
    test('the nodes of a database are listed', function () {
        $node = DatabaseNode::factory()->create();
        actingAsDatabaseKey('read');

        $response = mcpCall('list_database_nodes', ['database' => $node->database_id]);

        expect(array_column(mcpData($response), 'id'))->toBe([$node->id]);
    });

    test('a node is shown', function () {
        $node = DatabaseNode::factory()->create();
        actingAsDatabaseKey('read');

        $response = mcpCall('get_database_node', ['database' => $node->database_id, 'node' => $node->id]);

        expect(mcpData($response)['id'])->toBe($node->id);
    });

    test('a node of another database is not found', function () {
        $database = Database::factory()->create();
        $foreignNode = DatabaseNode::factory()->create();
        actingAsDatabaseKey('read');

        $response = mcpCall('get_database_node', ['database' => $database->id, 'node' => $foreignNode->id]);

        expect(mcpError($response))->toContain('No database_node with this id.');
    });

    test('a standby is added on the same private network', function () {
        Queue::fake([AddNodeJob::class]);
        $network = Network::factory()->create(['cidr' => '10.10.0.0/16']);
        $database = Database::factory()->create();
        DatabaseNode::factory()->for($database)->for(Server::factory()->ready()->onNetwork($network))->create();
        $server = Server::factory()->ready()->onNetwork($network)->create();
        actingAsDatabaseKey();

        $response = mcpCall('add_database_node', ['database' => $database->id, 'server_id' => $server->id]);

        expect(mcpData($response))->toMatchArray(['status' => 'scaling'])
            ->and(mcpData($response)['nodes'])->toHaveCount(2);
        $this->assertDatabaseHas('database_nodes', ['database_id' => $database->id, 'server_id' => $server->id]);
        Queue::assertPushed(AddNodeJob::class);
    });

    test('a read-only node is renamed', function () {
        $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports']);
        actingAsDatabaseKey();

        $response = mcpCall('update_database_node', ['database' => $readOnly->database_id, 'node' => $readOnly->id, 'name' => 'orders-analytics']);

        expect(mcpData($response)['name'])->toBe('orders-analytics');
        $this->assertDatabaseHas('database_nodes', ['id' => $readOnly->id, 'name' => 'orders-analytics']);
    });

    test('a read-only node is rebooted on its own', function () {
        Queue::fake([RebootNodeJob::class]);
        $readOnly = DatabaseNode::factory()->readOnly()->create();
        actingAsDatabaseKey();

        $response = mcpCall('reboot_database_node', ['database' => $readOnly->database_id, 'node' => $readOnly->id]);

        expect(mcpData($response)['status'])->toBe('rebooting');
        Queue::assertPushed(RebootNodeJob::class);
    });

    test('a member node is not rebooted on its own', function () {
        Queue::fake([RebootNodeJob::class]);
        $member = DatabaseNode::factory()->create();
        actingAsDatabaseKey();

        $response = mcpCall('reboot_database_node', ['database' => $member->database_id, 'node' => $member->id]);

        expect(mcpError($response))->toContain('Only read-only nodes have their own trusted sources, config and pools.');
        Queue::assertNotPushed(RebootNodeJob::class);
    });

    test('the only member is not removed', function () {
        Queue::fake([RemoveNodeJob::class]);
        $node = DatabaseNode::factory()->create();
        RecordingDriver::fake()->on('nodes.status', new NodeStatus(NodeRole::Primary, '18.1'));
        actingAsDatabaseKey();

        $response = mcpCall('delete_database_node', ['database' => $node->database_id, 'node' => $node->id]);

        expect(mcpError($response))->toContain("{$node->server->name} is the only member left.");
        $this->assertModelExists($node);
        Queue::assertNotPushed(RemoveNodeJob::class);
    });
});

describe('database insights', function () {
    test('database metric history defaults to the last hour', function () {
        $database = Database::factory()->create();
        DatabaseNode::factory()->for($database)->create();
        actingAsDatabaseKey('read');

        $response = mcpCall('get_database_metrics', ['database' => $database->id]);

        expect(mcpData($response)['period'])->toBe('1h');
    });

    test('node metric history covers only that node', function () {
        $database = Database::factory()->create();
        $node = DatabaseNode::factory()->for($database)->create();
        actingAsDatabaseKey('read');

        $response = mcpCall('get_database_metrics', ['database' => $database->id, 'node' => $node->id]);

        expect(array_column(mcpData($response)['nodes'], 'id'))->toBe([$node->id]);
    });

    test('an unknown metric period is refused', function () {
        $database = Database::factory()->create();
        actingAsDatabaseKey('read');

        $response = mcpCall('get_database_metrics', ['database' => $database->id, 'period' => '1y']);

        expect(mcpError($response))->toContain('The selected period is invalid.');
    });

    test('node logs are read from the engine', function () {
        $database = Database::factory()->create(['log_units' => ['postgres', 'patroni']]);
        $node = DatabaseNode::factory()->for($database)->create();
        $this->ssh()->replyTo('databasify logs read', json_encode(['units' => ['postgres'], 'lines' => ['ready to accept connections']]));
        actingAsDatabaseKey('read');

        $response = mcpCall('get_node_logs', ['database' => $database->id, 'node' => $node->id, 'service' => 'postgres', 'lines' => 50]);

        expect(mcpData($response))->toBe(['logs' => 'ready to accept connections']);
    });

    test('node logs are limited to the services the engine runs', function () {
        $database = Database::factory()->create(['log_units' => ['postgres']]);
        $node = DatabaseNode::factory()->for($database)->create();
        actingAsDatabaseKey('read');

        $response = mcpCall('get_node_logs', ['database' => $database->id, 'node' => $node->id, 'service' => 'sshd']);

        expect(mcpError($response))->toContain('The selected service is invalid.');
    });

    test('database logs are listed without their output', function () {
        $node = DatabaseNode::factory()->create();
        $log = ServerLog::factory()->for($node->server)->create(['database_id' => $node->database_id]);
        actingAsDatabaseKey('read');

        $response = mcpCall('list_database_logs', ['database' => $node->database_id]);

        expect(mcpData($response)['data'][0])->toMatchArray(['id' => $log->id])->not->toHaveKey('output');
    });

    test('a single database log shows its output', function () {
        $node = DatabaseNode::factory()->create();
        $log = ServerLog::factory()->for($node->server)->create(['database_id' => $node->database_id, 'output' => "creating cluster\n"]);
        actingAsDatabaseKey('read');

        $response = mcpCall('get_database_log', ['database' => $node->database_id, 'log' => $log->id]);

        expect(mcpData($response)['output'])->toBe("creating cluster\n");
    });

    test('a log of another database is not found', function () {
        $database = Database::factory()->create();
        $log = ServerLog::factory()->create();
        actingAsDatabaseKey('read');

        $response = mcpCall('get_database_log', ['database' => $database->id, 'log' => $log->id]);

        expect(mcpError($response))->toContain('No log with this id.');
    });
});
