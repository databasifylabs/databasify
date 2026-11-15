<?php

declare(strict_types=1);

use App\DTOs\Databases\NodeStatus;
use App\Engines\Data\EngineField;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\AddNodeJob;
use App\Jobs\Databases\ApplyNodeSettingsJob;
use App\Jobs\Databases\RebootNodeJob;
use App\Jobs\Databases\RemoveNodeJob;
use App\Jobs\Databases\RetuneNodeJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Network;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RecordingDriver;

function databaseOnNetwork(): Database
{
    $network = Network::factory()->create(['cidr' => '10.10.0.0/16']);
    $database = Database::factory()->create(['name' => 'orders']);
    DatabaseNode::factory()->for($database)->for(Server::factory()->ready()->onNetwork($network))->create();

    return $database;
}

function nodeRoute(DatabaseNode $node, string $route): string
{
    return route($route, [$node->database->project_id, $node->database, $node]);
}

test('a standby can be added on the same private network', function () {
    Queue::fake([AddNodeJob::class]);
    $database = databaseOnNetwork();
    $server = Server::factory()->ready()->onNetwork($database->seed()->server->network)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.nodes.store', [$database->project_id, $database]), ['server_id' => $server->id])
        ->assertSessionHasNoErrors();

    $node = $database->nodes()->where('server_id', $server->id)->sole();
    expect($node->kind)->toBe(NodeKind::Member)
        ->and($database->refresh()->status)->toBe(DatabaseStatus::Scaling);
    Queue::assertPushed(AddNodeJob::class, fn (AddNodeJob $job) => $job->node->is($node));
});

test('a read-only node needs a name', function () {
    Queue::fake([AddNodeJob::class]);
    $database = databaseOnNetwork();
    $server = Server::factory()->ready()->onNetwork($database->seed()->server->network)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.nodes.store', [$database->project_id, $database]), ['server_id' => $server->id, 'kind' => 'read_only'])
        ->assertSessionHasErrors(['name' => 'The name field is required when kind is read_only.']);

    Queue::assertNotPushed(AddNodeJob::class);
});

test('a database has room for two standbys at most', function () {
    Queue::fake([AddNodeJob::class]);
    $database = databaseOnNetwork();
    $network = $database->seed()->server->network;
    DatabaseNode::factory()->for($database)->for(Server::factory()->ready()->onNetwork($network))->replica(2)->create();
    DatabaseNode::factory()->for($database)->for(Server::factory()->ready()->onNetwork($network))->replica(3)->create();
    $server = Server::factory()->ready()->onNetwork($network)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('databases.nodes.store', [$database->project_id, $database]), ['server_id' => $server->id])
        ->assertSessionHasErrors(['server_id' => 'orders already has a primary and 2 standby nodes, the most it can have.']);

    Queue::assertNotPushed(AddNodeJob::class);
});

test('member node pages redirect to the database', function () {
    $node = DatabaseNode::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(nodeRoute($node, 'databases.nodes.show'))
        ->assertRedirect(route('databases.show', [$node->database->project_id, $node->database]));
});

test('read-only node page is displayed', function () {
    $database = Database::factory()->create();
    DatabaseNode::factory()->for($database)->create();
    $readOnly = DatabaseNode::factory()->for($database)->readOnly()->create(['name' => 'orders-reports']);

    $this->actingAs(User::factory()->create())
        ->get(nodeRoute($readOnly, 'databases.nodes.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('databases/nodes/show')->where('node.data.id', $readOnly->id));
});

test('a node can be renamed when the database has no domain', function () {
    $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports']);

    $this->actingAs(User::factory()->create())
        ->put(nodeRoute($readOnly, 'databases.nodes.update'), ['name' => 'orders-analytics'])
        ->assertSessionHasNoErrors();

    expect($readOnly->refresh()->name)->toBe('orders-analytics');
});

test('node config cannot change settings the primary controls', function () {
    Queue::fake([ApplyNodeSettingsJob::class]);
    $database = Database::factory()->create(['name' => 'orders', 'config_fields' => [
        new EngineField('max_connections', 'Max connections', '', 'integer', '100', [], null, 10, 1000, null, true, false),
        new EngineField('work_mem', 'Work memory', '', 'integer', '4', [], 'MB', 1, 1024, null, false, false),
    ]]);
    $readOnly = DatabaseNode::factory()->for($database)->readOnly()->create(['name' => 'orders-reports']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(nodeRoute($readOnly, 'databases.nodes.config'), ['config' => ['max_connections' => '500']])
        ->assertSessionHasErrors(['config' => 'max_connections is set on the primary for every node, change it on orders.']);

    $this->actingAs($user)
        ->put(nodeRoute($readOnly, 'databases.nodes.config'), ['config' => ['work_mem' => '64']])
        ->assertSessionHasNoErrors();

    expect($readOnly->refresh())
        ->config->toBe(['work_mem' => '64'])
        ->status->toBe(DatabaseStatus::Updating);
    Queue::assertPushed(ApplyNodeSettingsJob::class, 1);
});

test('only read-only nodes are rebooted, retuned or configured on their own', function (string $route) {
    Queue::fake([RebootNodeJob::class, RetuneNodeJob::class, ApplyNodeSettingsJob::class]);
    $member = DatabaseNode::factory()->create();

    $this->actingAs(User::factory()->create())
        ->call($route === 'databases.nodes.config' ? 'PUT' : 'POST', nodeRoute($member, $route), ['config' => []])
        ->assertSessionHasErrors(['database' => 'Only read-only nodes have their own trusted sources, config and pools.']);

    Queue::assertNothingPushed();
})->with(['databases.nodes.reboot', 'databases.nodes.retune', 'databases.nodes.config']);

test('a read-only node is rebooted or retuned on its own', function (string $route, string $job, DatabaseStatus $status) {
    Queue::fake([$job]);
    $readOnly = DatabaseNode::factory()->readOnly()->create(['name' => 'orders-reports']);

    $this->actingAs(User::factory()->create())->post(nodeRoute($readOnly, $route))->assertSessionHasNoErrors();

    expect($readOnly->refresh()->status)->toBe($status);
    Queue::assertPushed($job);
})->with([
    'reboot' => ['databases.nodes.reboot', RebootNodeJob::class, DatabaseStatus::Rebooting],
    'retune' => ['databases.nodes.retune', RetuneNodeJob::class, DatabaseStatus::Updating],
]);

test('removing the primary hands over to a standby', function () {
    Queue::fake([RemoveNodeJob::class]);
    $database = Database::factory()->highlyAvailable()->create();
    $primary = DatabaseNode::factory()->for($database)->create();
    $standby = DatabaseNode::factory()->for($database)->replica()->create();
    RecordingDriver::fake()->on('nodes.status', fn (DatabaseNode $node) => new NodeStatus($node->is($primary) ? NodeRole::Primary : NodeRole::Replica, '18.1'));

    $this->actingAs(User::factory()->create())
        ->delete(nodeRoute($primary, 'databases.nodes.destroy'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('databases.show', [$database->project_id, $database]));

    expect($database->refresh()->status)->toBe(DatabaseStatus::Scaling);
    Queue::assertPushed(RemoveNodeJob::class, fn (RemoveNodeJob $job) => $job->node->is($primary) && $job->standby?->is($standby) && ! $job->force);
});

test('the only member cannot be removed', function () {
    Queue::fake([RemoveNodeJob::class]);
    $node = DatabaseNode::factory()->create();
    RecordingDriver::fake()->on('nodes.status', new NodeStatus(NodeRole::Primary, '18.1'));

    $this->actingAs(User::factory()->create())
        ->delete(nodeRoute($node, 'databases.nodes.destroy'))
        ->assertSessionHasErrors(['node' => "{$node->server->name} is the only member left."]);

    expect($node->database->refresh()->status)->toBe(DatabaseStatus::Ready);
    Queue::assertNotPushed(RemoveNodeJob::class);
});
