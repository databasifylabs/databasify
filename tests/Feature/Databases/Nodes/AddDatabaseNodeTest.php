<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\Network;
use App\Models\Server;
use App\Models\User;
use App\Rules\ClusterName;
use Illuminate\Support\Facades\Queue;

it('requires a unique DigitalOcean-style name for read-only nodes', function (string $name, string $message) {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single, 'cache');
    withReadOnlyNode($database, ['name' => 'reports']);
    Queue::fake([AdvanceDatabaseFlow::class]);

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => Server::factory()->ready()->create()->id, 'kind' => 'read_only', 'name' => $name])
        ->assertSessionHasErrors(['name' => $message]);

    Queue::assertNothingPushed();
})->with([
    'missing' => ['', 'The name field is required when kind is read_only.'],
    'too short' => ['ab', ClusterName::FORMAT],
    'starts with a digit' => ['9reports', ClusterName::FORMAT],
    'ends with a dash' => ['reports-', ClusterName::FORMAT],
    'too long' => [str_repeat('a', 44), ClusterName::FORMAT],
    'private prefix' => ['private-reports', "Names can't start with private- or replica-, those prefixes are used for private and replica hostnames."],
    'replica prefix' => ['replica-cache', "Names can't start with private- or replica-, those prefixes are used for private and replica hostnames."],
    'node certificate name' => ['node-12', 'Names like node-12 are reserved for node certificates.'],
    'a database name' => ['cache', 'cache is already used by another database or read-only node.'],
    'another node name' => ['reports', 'reports is already used by another database or read-only node.'],
]);

it('adds a named read-only node from the database page', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AdvanceDatabaseFlow::class]);
    $server = Server::factory()->ready()->create();

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $server->id, 'kind' => 'read_only', 'name' => 'reports'])->assertRedirect();

    $reader = $database->nodes()->readOnly()->sole();
    expect($reader)->name->toBe('reports')->server_id->toBe($server->id)->position->toBe(1)
        ->and($database->fresh()->operation)->toBe('Add Read-only node reports');
    expect($database->flows()->running()->sole()->context->targetNodeId)->toBe($reader->id);
    Queue::assertPushed(AdvanceDatabaseFlow::class);
});

it('adds nodes only from the private network of the database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AdvanceDatabaseFlow::class]);
    $seed = $database->seed()->server;
    $elsewhere = Server::factory()->ready()->onNetwork(Network::factory()->create(['cidr' => '10.200.0.0/20']))->create(['name' => 'far']);

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $elsewhere->id, 'kind' => 'read_only'])
        ->assertSessionHasErrors(['server_id' => "far is not on {$seed->name}'s private network shared (10.0.0.0/8)."]);

    $seed->update(['network_id' => null]);
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => Server::factory()->ready()->create()->id])
        ->assertSessionHasErrors(['server_id' => "{$seed->name} has no private network. Standby and read-only nodes need one shared with it."]);

    expect($database->nodes()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('validates the server, kind and name of a new node', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AdvanceDatabaseFlow::class]);
    $free = Server::factory()->ready()->create();

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $database->seed()->server_id])->assertSessionHasErrors('server_id');
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => Server::factory()->create()->id])->assertSessionHasErrors('server_id');
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $free->id, 'kind' => 'witness'])->assertSessionHasErrors('kind');
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $free->id, 'name' => 'reports'])->assertSessionHasErrors('name');
    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $free->id, 'kind' => 'read_only', 'name' => 'Reports'])->assertSessionHasErrors('name');

    expect($database->nodes()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('refuses to add a node while the database is busy', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    Queue::fake([AdvanceDatabaseFlow::class]);
    $database->startOperation('Upgrade');

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => Server::factory()->ready()->create()->id])->assertSessionHasErrors('database');

    expect($database->nodes()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('adds a standby on a server with less than 1 GB of memory', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    Queue::fake([AdvanceDatabaseFlow::class]);
    $database->seed()->server->update(['memory_mb' => 480]);
    $small = Server::factory()->ready()->create(['memory_mb' => 480]);

    $this->post(projectUrl("/databases/{$database->id}/nodes"), ['server_id' => $small->id])->assertSessionHasNoErrors();

    expect($database->nodes()->members()->pluck('server_id'))->toContain($small->id);
});
