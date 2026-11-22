<?php

declare(strict_types=1);

use App\Enums\Servers\ServerStatus;
use App\Jobs\Servers\ProvisionServerJob;
use App\Jobs\Servers\RebootServerJob;
use App\Jobs\Servers\UpdateServerPackagesJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerLog;
use Illuminate\Support\Facades\Queue;

describe('reading servers', function () {
    test('a project lists only its own servers', function () {
        $server = Server::factory()->for(Project::factory())->create();
        Server::factory()->for(Project::factory())->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

        $response = mcpCall('list_servers', ['project' => $server->project_id]);

        expect(array_column(mcpData($response)['data'], 'id'))->toBe([$server->id]);
    });

    test('the server list filters by status', function () {
        $project = Project::factory()->create();
        $ready = Server::factory()->ready()->for($project)->create();
        Server::factory()->for($project)->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

        $response = mcpCall('list_servers', ['status' => 'ready']);

        expect(array_column(mcpData($response)['data'], 'id'))->toBe([$ready->id]);
    });

    test('a server shows the database node it runs', function () {
        $server = Server::factory()->ready()->create();
        $node = DatabaseNode::factory()->for(Database::factory()->create(['name' => 'orders']))->for($server)->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

        $response = mcpCall('get_server', ['server' => $server->id]);

        expect(mcpData($response)['database'])->toMatchArray(['name' => 'orders', 'node_id' => $node->id]);
    });

    test('a server in another project is not found for a limited key', function () {
        $server = Server::factory()->for(Project::factory())->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->limitedTo(Project::factory()->create())->create());

        $response = mcpCall('get_server', ['server' => $server->id]);

        expect(mcpError($response))->toContain('No server with this id.');
    });

    test('the app ssh key is an ed25519 public key', function () {
        actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

        $response = mcpCall('get_server_ssh_key');

        expect(mcpData($response)['public_key'])->toStartWith('ssh-ed25519 ');
    });

    test('server users are listed', function () {
        $server = Server::factory()->ready()->create();
        $server->users()->create(['username' => 'deploy']);
        actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

        $response = mcpCall('list_server_users', ['server' => $server->id]);

        expect(array_column(mcpData($response)['data'], 'username'))->toBe(['deploy']);
    });

    test('server logs are listed without their output', function () {
        $log = ServerLog::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

        $response = mcpCall('list_server_logs', ['server' => $log->server_id]);

        expect(mcpData($response)['data'][0])->toMatchArray(['id' => $log->id])->not->toHaveKey('output');
    });

    test('a single server log shows its output', function () {
        $log = ServerLog::factory()->create(['output' => "Reading package lists...\n"]);
        actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

        $response = mcpCall('get_server_log', ['server' => $log->server_id, 'log' => $log->id]);

        expect(mcpData($response)['output'])->toBe("Reading package lists...\n");
    });

    test('a log of another server is not found', function () {
        $server = Server::factory()->create();
        $log = ServerLog::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

        $response = mcpCall('get_server_log', ['server' => $server->id, 'log' => $log->id]);

        expect(mcpError($response))->toContain('No log with this id.');
    });
});

describe('changing servers', function () {
    test('adding a server queues provisioning and never returns the password', function () {
        Queue::fake([ProvisionServerJob::class]);
        $project = Project::factory()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('create_server', ['project' => $project->id, 'name' => 'db-1', 'public_ip' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root', 'ssh_password' => 'temporary-root-password']);

        expect(mcpData($response))->toMatchArray(['name' => 'db-1', 'status' => 'pending', 'project_id' => $project->id])
            ->and(json_encode($response))->not->toContain('temporary-root-password');
        $this->assertDatabaseHas('servers', ['project_id' => $project->id, 'name' => 'db-1', 'public_ip' => '203.0.113.10']);
        Queue::assertPushed(ProvisionServerJob::class);
    });

    test('a server in a database is not deleted', function () {
        $server = Server::factory()->ready()->create(['name' => 'db-1']);
        DatabaseNode::factory()->for(Database::factory())->for($server)->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('delete_server', ['server' => $server->id]);

        expect(mcpError($response))->toContain('db-1 is part of a database, remove it from the database first.');
        $this->assertModelExists($server);
    });

    test('an unused server is deleted', function () {
        $server = Server::factory()->ready()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('delete_server', ['server' => $server->id]);

        expect(mcpData($response))->toBe(['deleted' => $server->id]);
        $this->assertModelMissing($server);
    });

    test('an operation is queued on a ready server', function (string $operation, string $job) {
        Queue::fake([$job]);
        $server = Server::factory()->ready()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('run_server_operation', ['server' => $server->id, 'operation' => $operation]);

        expect(mcpData($response)['id'])->toBe($server->id);
        Queue::assertPushed($job);
    })->with([
        'update packages' => ['update-packages', UpdateServerPackagesJob::class],
        'reboot' => ['reboot', RebootServerJob::class],
    ]);

    test('an operation on a server that is not ready is refused', function () {
        Queue::fake([RebootServerJob::class]);
        $server = Server::factory()->create(['name' => 'db-1']);
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('run_server_operation', ['server' => $server->id, 'operation' => 'reboot']);

        expect(mcpError($response))->toContain('db-1 is not ready yet.');
        Queue::assertNotPushed(RebootServerJob::class);
    });

    test('an unknown operation is refused', function () {
        Queue::fake([RebootServerJob::class, UpdateServerPackagesJob::class]);
        $server = Server::factory()->ready()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('run_server_operation', ['server' => $server->id, 'operation' => 'format-disk']);

        expect(mcpError($response))->toContain('The selected operation is invalid.');
        Queue::assertNothingPushed();
    });

    test('a server is provisioned again', function () {
        Queue::fake([ProvisionServerJob::class]);
        $server = Server::factory()->ready()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('provision_server', ['server' => $server->id]);

        expect(mcpData($response)['status'])->toBe('pending');
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'status' => ServerStatus::Pending->value]);
        Queue::assertPushed(ProvisionServerJob::class);
    });

    test('provisioning a server already being set up is refused', function () {
        Queue::fake([ProvisionServerJob::class]);
        $server = Server::factory()->create(['name' => 'db-1', 'status' => ServerStatus::Provisioning]);
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('provision_server', ['server' => $server->id]);

        expect(mcpError($response))->toContain('db-1 is already being set up.');
        Queue::assertNotPushed(ProvisionServerJob::class);
    });

    test('refreshing a server stores the facts read over ssh', function () {
        $server = Server::factory()->ready()->create(['cpus' => 2]);
        $this->ssh()->replyTo('/etc/os-release', '{"os":"Ubuntu 24.04 LTS","id":"ubuntu","version_id":"24.04","selinux":"Disabled","cpus":8,"memory_mb":16384,"disk_gb":160,"subnets":[]}'."\n");
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('refresh_server', ['server' => $server->id]);

        expect(mcpData($response)['cpus'])->toBe(8);
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'cpus' => 8, 'memory_mb' => 16384]);
    });

    test('the private network of a server changes', function () {
        $server = Server::factory()->ready()->onNetwork(null)->create();
        $network = Network::factory()->for($server->project)->create(['cidr' => '10.10.0.0/16']);
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('update_server_network', ['server' => $server->id, 'network' => $network->id, 'private_ip' => '10.10.0.7']);

        expect(mcpData($response))->toMatchArray(['network_id' => $network->id, 'private_ip' => '10.10.0.7']);
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'network_id' => $network->id, 'private_ip' => '10.10.0.7']);
    });

    test('a server user is created on the server', function () {
        $server = Server::factory()->ready()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('create_server_user', ['server' => $server->id, 'username' => 'deploy', 'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBk deploy@laptop']);

        expect(mcpData($response)['username'])->toBe('deploy')
            ->and($this->ssh()->ran('useradd --create-home --shell /bin/bash deploy', $server))->toBeTrue();
        $this->assertDatabaseHas('server_users', ['server_id' => $server->id, 'username' => 'deploy']);
    });

    test('root is refused as a server user', function () {
        $server = Server::factory()->ready()->create();
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('create_server_user', ['server' => $server->id, 'username' => 'root']);

        expect(mcpError($response))->toContain('The selected username is invalid.');
        $this->assertDatabaseMissing('server_users', ['server_id' => $server->id]);
    });

    test('a server user is deleted', function () {
        $server = Server::factory()->ready()->create();
        $user = $server->users()->create(['username' => 'deploy']);
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('delete_server_user', ['server' => $server->id, 'user' => $user->id]);

        expect(mcpData($response))->toBe(['deleted' => $user->id]);
        $this->assertModelMissing($user);
    });

    test('a user of another server is not found and kept', function () {
        $server = Server::factory()->ready()->create();
        $otherUser = Server::factory()->ready()->create()->users()->create(['username' => 'deploy']);
        actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

        $response = mcpCall('delete_server_user', ['server' => $server->id, 'user' => $otherUser->id]);

        expect(mcpError($response))->toContain('No server_user with this id.');
        $this->assertModelExists($otherUser);
    });
});
