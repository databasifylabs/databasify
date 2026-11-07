<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Enums\Servers\ServerLogStatus;
use App\Jobs\Databases\CheckDatabaseUpgradeJob;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerLog;
use App\Models\User;
use Database\Factories\ProjectFactory;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    ProjectFactory::defaultProject();
    $this->other = Project::factory()->create();
});

it('returns 404 for a server or database of another project', function () {
    $server = Server::factory()->for($this->other)->create();
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $database->update(['project_id' => $this->other->id]);

    $this->get(projectUrl("/servers/{$server->id}"))->assertNotFound();
    $this->get(projectUrl("/databases/{$database->id}"))->assertNotFound();
});

it('lists and finds only the current project servers', function () {
    Server::factory()->create(['name' => 'web-own']);
    Server::factory()->for($this->other)->create(['name' => 'web-other']);

    $this->get(projectUrl('/servers'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('servers.data', 1)
        ->where('servers.data.0.name', 'web-own'));
    $this->getJson(projectUrl('/search?q=web'))->assertJsonCount(1, 'results')->assertJsonPath('results.0.title', 'web-own');
});

it('shows only the current project on its dashboard', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    ServerLog::factory()->for($database->nodes()->sole()->server)->for($database)->create(['status' => ServerLogStatus::Running]);

    $this->get("/{$this->other->id}/dashboard")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summary', [
            'databases' => ['total' => 0, 'online' => 0],
            'servers' => ['total' => 0, 'in_use' => 0, 'available' => 0],
            'nodes' => ['total' => 0, 'read_only' => 0],
            'running_logs' => 0,
        ])
        ->has('databases.data', 0)
        ->has('servers.data', 0)
        ->has('logs.data', 0));

    $this->get(projectUrl('/dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summary.databases.total', 1)
        ->where('summary.servers.total', 1)
        ->where('summary.nodes.total', 1)
        ->where('summary.running_logs', 1)
        ->has('databases.data', 1)
        ->has('servers.data', 1)
        ->has('logs.data', 1));
});

it('returns 404 for an unknown project', function () {
    $this->get('/0199a1b2-0000-7000-8000-000000000000/servers')->assertNotFound();
});

it('puts a new server in the project of the url', function () {
    Queue::fake([ProvisionServerJob::class]);

    $this->post("/{$this->other->id}/servers", ['name' => 'db-1', 'public_ip' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root'])
        ->assertSessionHasNoErrors();

    expect(Server::query()->sole()->project_id)->toBe($this->other->id);
});

it('lets scheduled commands see every project', function () {
    idleDatabase('valkey', 1, DatabaseMode::Single, 'own');
    idleDatabase('valkey', 1, DatabaseMode::Single, 'other')->update(['project_id' => $this->other->id]);
    Queue::fake([CheckDatabaseUpgradeJob::class]);

    $this->artisan('databases:check-upgrades')->assertSuccessful();

    Queue::assertPushed(CheckDatabaseUpgradeJob::class, 2);
});

it('refuses a private address that a server moved to another project still holds', function () {
    Queue::fake([ProvisionServerJob::class]);
    $network = Network::factory()->create(['cidr' => '10.104.0.0/20']);
    Server::factory()->for($this->other)->create(['network_id' => $network->id, 'private_ip' => '10.104.0.9', 'name' => 'moved']);

    $this->post(projectUrl('/servers'), ['name' => 'db-1', 'public_ip' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root', 'network' => $network->id, 'private_ip' => '10.104.0.9'])
        ->assertSessionHasErrors(['private_ip' => 'moved already uses 10.104.0.9 on this network.']);
});
