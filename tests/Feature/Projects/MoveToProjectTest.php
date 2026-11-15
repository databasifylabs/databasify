<?php

declare(strict_types=1);

use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageProvider;
use App\Models\User;

test('a resource can be moved to another project', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $provider = StorageProvider::factory()->for($source)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('moves.store', $source), ['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $target->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('storage-providers.index', $target));

    expect($provider->refresh()->project_id)->toBe($target->id);
});

test('a database moves together with its servers', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $database = Database::factory()->for($source)->create();
    $node = DatabaseNode::factory()->for($database)->for(Server::factory()->ready()->for($source))->create();

    $this->actingAs(User::factory()->create())
        ->post(route('moves.store', $source), ['type' => 'database', 'id' => $database->id, 'project_id' => $target->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('databases.show', ['project' => $target, 'database' => $database]));

    expect($database->refresh()->project_id)->toBe($target->id)
        ->and($node->server->refresh()->project_id)->toBe($target->id);
});

test('a server that belongs to a database cannot be moved on its own', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $database = Database::factory()->for($source)->create(['name' => 'orders']);
    $server = Server::factory()->ready()->for($source)->create();
    DatabaseNode::factory()->for($database)->for($server)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('moves.store', $source), ['type' => 'server', 'id' => $server->id, 'project_id' => $target->id])
        ->assertSessionHasErrors(['id' => 'Move database orders instead; its servers move with it.']);

    expect($server->refresh()->project_id)->toBe($source->id);
});

test('a resource cannot be moved into the project it is already in', function () {
    $project = Project::factory()->create();
    $provider = StorageProvider::factory()->for($project)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('moves.store', $project), ['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $project->id])
        ->assertSessionHasErrors(['project_id' => 'Pick a different project.']);
});

test('a resource of another project cannot be moved from here', function () {
    $current = Project::factory()->create();
    $other = Project::factory()->create();
    $provider = StorageProvider::factory()->for($other)->create();
    $target = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('moves.store', $current), ['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $target->id])
        ->assertSessionHasErrors(['id' => 'The selected id is invalid.']);

    expect($provider->refresh()->project_id)->toBe($other->id);
});
