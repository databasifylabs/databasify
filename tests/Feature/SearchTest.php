<?php

declare(strict_types=1);

use App\Models\Database;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;

test('search returns matching databases and servers of the current project only', function () {
    $current = Project::factory()->create();
    $other = Project::factory()->create();
    Database::factory()->for($current)->create(['name' => 'orders-db']);
    Server::factory()->for($current)->create(['name' => 'orders-1']);
    Database::factory()->for($other)->create(['name' => 'orders-elsewhere']);

    $this->actingAs(User::factory()->create())
        ->getJson(route('search', ['project' => $current, 'q' => 'orders']))
        ->assertOk()
        ->assertJsonCount(2, 'results')
        ->assertJsonPath('results.0.title', 'orders-db')
        ->assertJsonPath('results.1.title', 'orders-1');
});

test('search requires a term', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('search', ['project' => $project]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['q' => 'The q field is required.']);
});
