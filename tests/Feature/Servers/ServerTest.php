<?php

declare(strict_types=1);

use App\Enums\Servers\ServerStatus;
use App\Jobs\Networks\SyncWireguardNetworkJob;
use App\Jobs\Servers\ProvisionServerJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('servers page is displayed', function () {
    $project = Project::factory()->create();
    Server::factory()->for($project)->create(['name' => 'web-1']);

    $this->actingAs(User::factory()->create())
        ->get(route('servers.index', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('servers/index')->has('servers.data', 1));
});

test('add server page shows the app public key', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('servers.create', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/create')
            ->where('publicKey', fn (string $key) => str_starts_with($key, 'ssh-ed25519 ')));
});

test('a server can be added and is queued for provisioning', function () {
    Queue::fake([ProvisionServerJob::class]);
    $project = Project::factory()->create();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('servers.store', $project), [
            'name' => 'db-1',
            'public_ip' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'disable_password_login' => true,
        ]);

    $server = Server::query()->sole();
    $response->assertSessionHasNoErrors()->assertRedirect(route('servers.show', [$project, $server]));
    expect($server)
        ->project_id->toBe($project->id)
        ->status->toBe(ServerStatus::Pending)
        ->network_id->toBeNull();
    Queue::assertPushed(ProvisionServerJob::class);
});

test('adding a server requires its connection details', function () {
    Queue::fake([ProvisionServerJob::class]);
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('servers.store', $project), [])
        ->assertSessionHasErrors(['name', 'public_ip', 'ssh_port', 'ssh_user']);

    expect(Server::query()->exists())->toBeFalse();
    Queue::assertNotPushed(ProvisionServerJob::class);
});

test('a server private address must be inside the chosen network', function () {
    $project = Project::factory()->create();
    $network = Network::factory()->for($project)->create(['cidr' => '10.10.0.0/16']);

    $this->actingAs(User::factory()->create())
        ->post(route('servers.store', $project), [
            'name' => 'db-1',
            'public_ip' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'network' => $network->id,
            'private_ip' => '192.168.1.5',
        ])
        ->assertSessionHasErrors(['private_ip' => 'Use an address inside 10.10.0.0/16.']);
});

test('a server private address cannot be taken twice on a network', function () {
    $project = Project::factory()->create();
    $network = Network::factory()->for($project)->create(['cidr' => '10.10.0.0/16']);
    Server::factory()->for($project)->onNetwork($network)->create(['name' => 'db-1', 'private_ip' => '10.10.0.5']);

    $this->actingAs(User::factory()->create())
        ->post(route('servers.store', $project), [
            'name' => 'db-2',
            'public_ip' => '203.0.113.11',
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'network' => $network->id,
            'private_ip' => '10.10.0.5',
        ])
        ->assertSessionHasErrors(['private_ip' => 'db-1 already uses 10.10.0.5 on this network.']);
});

test('server page is displayed', function () {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('servers.show', [$server->project_id, $server]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('servers/show')->where('server.data.id', $server->id));
});

test('a server can be deleted and its wireguard network resynced', function () {
    Queue::fake([SyncWireguardNetworkJob::class]);
    $network = Network::factory()->wireguard()->create();
    $server = Server::factory()->ready()->onNetwork($network)->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('servers.destroy', [$server->project_id, $server]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('servers.index', $server->project_id));

    $this->assertModelMissing($server);
    Queue::assertPushed(SyncWireguardNetworkJob::class, fn (SyncWireguardNetworkJob $job) => $job->network->is($network));
});

test('a server that is part of a database cannot be deleted', function () {
    $server = Server::factory()->ready()->create(['name' => 'db-1']);
    DatabaseNode::factory()->for(Database::factory())->for($server)->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('servers.destroy', [$server->project_id, $server]))
        ->assertSessionHasErrors(['server' => 'db-1 is part of a database, remove it from the database first.']);

    $this->assertModelExists($server);
});

test('a server still being created cannot be deleted', function () {
    $server = Server::factory()->create(['name' => 'db-1', 'status' => ServerStatus::Creating]);

    $this->actingAs(User::factory()->create())
        ->delete(route('servers.destroy', [$server->project_id, $server]))
        ->assertSessionHasErrors(['server' => 'db-1 is still being created, wait until it finishes.']);

    $this->assertModelExists($server);
});
