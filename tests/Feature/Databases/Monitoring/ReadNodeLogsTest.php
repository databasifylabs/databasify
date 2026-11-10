<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Models\User;

it('serves logs only for the engine services', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('mysql', 1, DatabaseMode::Single);
    $node = $database->seed();
    $ssh = fakeEngine(fakeSsh()->respondToEngine('logs read', ['units' => ['mysql'], 'lines' => ['2026-10-23T00:00:00Z ready for connections']]));

    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs?service=router"))->assertUnprocessable();
    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs?service=mysql&lines=50"))
        ->assertOk()->assertJson(['logs' => '2026-10-23T00:00:00Z ready for connections']);

    expect(json_decode($ssh->engineRequestAt(0), true))->toBe(['unit' => 'mysql', 'lines' => 50]);
});

it('reads logs of the requested engine unit with logs read', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('postgres', 1, DatabaseMode::Single);
    $ssh = fakeEngine(fakeSsh()->respondToEngine('logs read', ['units' => ['postgres'], 'lines' => ['first', 'second']]));

    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$database->seed()->id}/logs?service=postgres"))->assertOk()->assertExactJson(['logs' => "first\nsecond"]);

    expect(json_decode($ssh->engineRequestAt(0), true))->toBe(['unit' => 'postgres', 'lines' => 200]);
});

it('limits how many log lines can be read', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $node = $database->seed();
    $ssh = fakeEngine();

    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs?service=databasify-valkey&lines=5"))->assertUnprocessable()->assertJsonValidationErrors('lines');
    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs?service=databasify-valkey&lines=2001"))->assertUnprocessable()->assertJsonValidationErrors('lines');
    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$node->id}/logs"))->assertUnprocessable()->assertJsonValidationErrors('service');

    expect($ssh->runs)->toBeEmpty();
});

it('hides the logs of a node from another database', function () {
    $this->actingAs(User::factory()->create());
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $other = idleDatabase('valkey', 1, DatabaseMode::Single, 'other');
    $ssh = fakeEngine();

    $this->getJson(projectUrl("/databases/{$database->id}/nodes/{$other->seed()->id}/logs?service=databasify-valkey"))->assertNotFound();

    expect($ssh->runs)->toBeEmpty();
});
