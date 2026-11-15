<?php

declare(strict_types=1);

use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\NodeMetricSample;
use App\Models\User;

function sampleOf(DatabaseNode $node, string $at, float $cpu): void
{
    NodeMetricSample::query()->create(['database_node_id' => $node->id, 'sampled_at' => $at, 'cpu_percent' => $cpu, 'load_1m' => 0.5, 'memory_percent' => 40, 'disk_percent' => 20, 'connections' => 5]);
}

test('metric history averages each member per minute over the last hour', function () {
    $this->travelTo('2026-11-15 12:00:00');
    $database = Database::factory()->create();
    $node = DatabaseNode::factory()->for($database)->create();
    sampleOf($node, '2026-11-15 11:58:10', 20);
    sampleOf($node, '2026-11-15 11:58:40', 40);
    sampleOf($node, '2026-11-15 10:30:00', 99);

    $this->actingAs(User::factory()->create())
        ->getJson(route('databases.metrics.history', [$database->project_id, $database]))
        ->assertOk()
        ->assertJsonPath('period', '1h')
        ->assertJsonPath('bucket_seconds', 60)
        ->assertJsonCount(1, 'points')
        ->assertJsonPath("points.0.values.{$node->id}.cpu_percent", 30);
});

test('metric history refuses unknown periods', function () {
    $database = Database::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('databases.metrics.history', ['project' => $database->project_id, 'database' => $database, 'period' => '1y']))
        ->assertJsonValidationErrors('period');
});

test('node metric history covers only that node', function () {
    $this->travelTo('2026-11-15 12:00:00');
    $database = Database::factory()->highlyAvailable()->create();
    $primary = DatabaseNode::factory()->for($database)->create();
    $replica = DatabaseNode::factory()->for($database)->replica()->create();
    sampleOf($primary, '2026-11-15 11:58:10', 20);
    sampleOf($replica, '2026-11-15 11:58:10', 80);

    $this->actingAs(User::factory()->create())
        ->getJson(route('databases.nodes.metrics.history', [$database->project_id, $database, $replica]))
        ->assertOk()
        ->assertJsonCount(1, 'nodes')
        ->assertJsonPath('nodes.0.id', $replica->id)
        ->assertJsonPath("points.0.values.{$replica->id}.cpu_percent", 80);
});

test('node logs are read from the engine', function () {
    $database = Database::factory()->create(['log_units' => ['postgres', 'patroni']]);
    $node = DatabaseNode::factory()->for($database)->create();
    $this->ssh()->replyTo('databasify logs read', json_encode(['units' => ['postgres'], 'lines' => ['ready to accept connections', 'checkpoint complete']]));

    $this->actingAs(User::factory()->create())
        ->getJson(route('databases.nodes.logs', ['project' => $database->project_id, 'database' => $database, 'node' => $node, 'service' => 'postgres', 'lines' => 50]))
        ->assertOk()
        ->assertExactJson(['logs' => "ready to accept connections\ncheckpoint complete"]);
});

test('node logs are limited to the services the engine runs', function () {
    $database = Database::factory()->create(['log_units' => ['postgres']]);
    $node = DatabaseNode::factory()->for($database)->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('databases.nodes.logs', ['project' => $database->project_id, 'database' => $database, 'node' => $node, 'service' => 'sshd']))
        ->assertJsonValidationErrors('service');

    expect($this->ssh()->ranNothing())->toBeTrue();
});

test('a node of another database returns 404', function () {
    $database = Database::factory()->create();
    $foreignNode = DatabaseNode::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('databases.nodes.metrics.history', [$database->project_id, $database, $foreignNode]))
        ->assertNotFound();
});
