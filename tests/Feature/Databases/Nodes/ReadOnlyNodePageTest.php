<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Models\NodeMetricSample;
use App\Models\ServerLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('shows a read-only node on its own page with its own host and the database port', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database, ['name' => 'main-ro-1']);
    $log = ServerLog::factory()->for($reader->server)->create(['database_id' => $database->id]);

    $this->get("/databases/{$database->id}/nodes/{$reader->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('databases/nodes/show')
        ->where('node.data.name', 'main-ro-1')
        ->where('node.data.server_name', $reader->server->name)
        ->where('endpoints.public', ['host' => $reader->server->public_ip, 'port' => 25061])
        ->where('appEnv.REDIS_HOST', $reader->server->public_ip)
        ->where('database.data.max_standbys', 2)
        ->where('latestLogId', $log->id));

    $this->get("/databases/{$database->id}/nodes/{$database->seed()->id}")->assertRedirect("/databases/{$database->id}");
});

it('keeps read-only nodes out of the database insights and shows them on their own', function () {
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($database, ['name' => 'main-ro-1']);
    $database->nodes()->get()->each(fn ($node) => NodeMetricSample::query()->create(['database_node_id' => $node->id, 'sampled_at' => now(), 'cpu_percent' => 5]));

    $this->getJson("/databases/{$database->id}/metrics/history")->assertJsonPath('nodes', [['id' => $database->seed()->id, 'name' => $database->seed()->server->name]]);
    $this->getJson("/databases/{$database->id}/nodes/{$reader->id}/metrics/history")->assertJsonPath('nodes', [['id' => $reader->id, 'name' => 'main-ro-1']]);
});
