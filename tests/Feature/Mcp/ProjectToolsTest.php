<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageProvider;

test('projects are listed with their names', function () {
    $project = Project::factory()->create(['name' => 'Production']);
    actingAsApiKey(ApiKey::factory()->withScopes('projects:read')->create());

    $response = mcpCall('list_projects');

    expect(mcpData($response)['data'][0])->toMatchArray(['id' => $project->id, 'name' => 'Production']);
});

test('a project is created', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->create());

    $response = mcpCall('create_project', ['name' => 'Staging']);

    expect(mcpData($response)['name'])->toBe('Staging');
    $this->assertDatabaseHas('projects', ['name' => 'Staging']);
});

test('a key limited to some projects cannot create a project', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo(Project::factory()->create())->create());

    $response = mcpCall('create_project', ['name' => 'Staging']);

    expect(mcpError($response))->toContain('This action is unauthorized.');
    $this->assertDatabaseMissing('projects', ['name' => 'Staging']);
});

test('a project is renamed', function () {
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo($project)->create());

    $response = mcpCall('update_project', ['project' => $project->id, 'name' => 'Renamed']);

    expect(mcpData($response)['name'])->toBe('Renamed');
    $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Renamed']);
});

test('a project outside the key is not found and keeps its name', function () {
    $other = Project::factory()->create(['name' => 'Other']);
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo(Project::factory()->create())->create());

    $response = mcpCall('update_project', ['project' => $other->id, 'name' => 'Renamed']);

    expect(mcpError($response))->toContain('No project with this id.');
    $this->assertDatabaseHas('projects', ['id' => $other->id, 'name' => 'Other']);
});

test('an empty project is deleted', function () {
    Project::factory()->create();
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->create());

    $response = mcpCall('delete_project', ['project' => $project->id]);

    expect(mcpData($response))->toBe(['deleted' => $project->id]);
    $this->assertModelMissing($project);
});

test('a project that still has resources is not deleted', function () {
    $project = Project::factory()->create(['name' => 'Production']);
    Server::factory()->for($project)->create();
    Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->create());

    $response = mcpCall('delete_project', ['project' => $project->id]);

    expect(mcpError($response))->toContain('Production still has servers, databases, networks or providers. Move or delete them first.');
    $this->assertModelExists($project);
});

test('a storage provider moves between allowed projects', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $provider = StorageProvider::factory()->for($source)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo($source, $target)->create());

    $response = mcpCall('move_to_project', ['project' => $source->id, 'type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $target->id]);

    expect(mcpData($response))->toBe(['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $target->id]);
    $this->assertDatabaseHas('storage_providers', ['id' => $provider->id, 'project_id' => $target->id]);
});

test('a move into a project outside the key is refused', function () {
    $source = Project::factory()->create();
    $outside = Project::factory()->create();
    $provider = StorageProvider::factory()->for($source)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo($source)->create());

    $response = mcpCall('move_to_project', ['project' => $source->id, 'type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $outside->id]);

    expect(mcpError($response))->toContain('The selected project id is invalid.');
    $this->assertDatabaseHas('storage_providers', ['id' => $provider->id, 'project_id' => $source->id]);
});
