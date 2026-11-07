<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Models\Database;
use App\Models\User;

it('serves logs only for the engine services', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $node = $database->seed();
    $ssh = fakeSsh()->respond("2026-10-23T00:00:00Z ready for connections\n");

    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs?service=router"))->assertUnprocessable();
    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs?service=mysql&lines=50"))
        ->assertOk()->assertJson(['logs' => "2026-10-23T00:00:00Z ready for connections\n"]);

    expect($ssh->scriptAt(0))->toContain('docker exec databasify-mysql databasify ctl logs --unit mysql --lines 50 --output short-iso');
});

it('reads logs of the requested engine process from its container', function (string $engine, string $service) {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase($engine, 1, DatabaseMode::Single);
    $ssh = fakeSsh()->respond("line\n");

    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$database->seed()->id}/logs?service={$service}"))->assertOk()->assertExactJson(['logs' => "line\n"]);

    expect($ssh->scriptAt(0))->toContain('docker exec '.Database::providerFor($engine)->container()." databasify ctl logs --unit {$service} --lines 200 --output short-iso");
})->with([
    'valkey' => ['valkey', 'databasify-valkey'],
    'redis' => ['redis', 'databasify-redis'],
    'postgres pooler' => ['postgres', 'databasify-pgbouncer'],
    'mariadb' => ['mariadb', 'mariadb'],
    'mongodb' => ['mongodb', 'mongod'],
]);

it('limits how many log lines can be read', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $node = $database->seed();
    $ssh = fakeSsh();

    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs?service=databasify-valkey&lines=5"))->assertUnprocessable()->assertJsonValidationErrors('lines');
    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs?service=databasify-valkey&lines=2001"))->assertUnprocessable()->assertJsonValidationErrors('lines');
    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs"))->assertUnprocessable()->assertJsonValidationErrors('service');

    expect($ssh->runs)->toBeEmpty();
});

it('hides the logs of a node from another database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $other = idleDatabase('valkey', 1, DatabaseMode::Single, 'other');
    $ssh = fakeSsh();

    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$other->seed()->id}/logs?service=databasify-valkey"))->assertNotFound();

    expect($ssh->runs)->toBeEmpty();
});
