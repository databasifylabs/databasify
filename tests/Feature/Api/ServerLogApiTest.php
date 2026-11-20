<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerLog;

test('server logs are listed without output', function () {
    $log = ServerLog::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson("/api/v1/servers/{$log->server_id}/logs")
        ->assertOk()
        ->assertJsonPath('data.0.id', $log->id)
        ->assertJsonMissingPath('data.0.output')
        ->assertJsonStructure(['data' => [['id', 'name', 'status', 'exit_code', 'server_id', 'database_id', 'created_at', 'updated_at']], 'links', 'meta']);
});

test('a single log shows its output', function () {
    $log = ServerLog::factory()->create(['output' => "Reading package lists...\n"]);
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson("/api/v1/servers/{$log->server_id}/logs/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.output', "Reading package lists...\n");
});

test('a log of another server is not found', function () {
    $server = Server::factory()->create();
    $log = ServerLog::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson("/api/v1/servers/{$server->id}/logs/{$log->id}")->assertNotFound();
});

test('logs of a server in another project are not found for a limited key', function () {
    $log = ServerLog::factory()->for(Server::factory()->for(Project::factory()))->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->limitedTo(Project::factory()->create())->create());

    $this->getJson("/api/v1/servers/{$log->server_id}/logs")->assertNotFound();
});
