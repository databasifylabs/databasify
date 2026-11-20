<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageProvider;

test('listing projects shows only the key projects', function () {
    $mine = Project::factory()->create(['name' => 'Staging']);
    Project::factory()->create(['name' => 'Production']);
    actingAsApiKey(ApiKey::factory()->withScopes('projects:read')->limitedTo($mine)->create());

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Staging')
        ->assertJsonStructure(['data' => [['id', 'name', 'servers_count', 'databases_count', 'created_at']], 'links', 'meta']);
});

test('creating a project needs projects:write', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('projects:read')->create());

    $this->postJson('/api/v1/projects', ['name' => 'Read only'])->assertForbidden();
});

test('a limited key cannot create a project', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo(Project::factory()->create())->create());

    $this->postJson('/api/v1/projects', ['name' => 'Limited'])->assertForbidden();
});

test('an all-projects key creates a project', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->create());

    $this->postJson('/api/v1/projects', ['name' => 'Analytics'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Analytics');
});

test('renaming a project', function () {
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo($project)->create());

    $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

test('renaming a project outside the key is not found', function () {
    $other = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo(Project::factory()->create())->create());

    $this->patchJson("/api/v1/projects/{$other->id}", ['name' => 'Renamed'])->assertNotFound();
});

test('deleting an empty project', function () {
    Project::factory()->create();
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->create());

    $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

    expect(Project::query()->find($project->id))->toBeNull();
});

test('deleting a project that still has resources is refused', function () {
    $project = Project::factory()->create();
    Server::factory()->for($project)->create();
    Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->create());

    $this->deleteJson("/api/v1/projects/{$project->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project');
});

test('a limited key cannot delete a project', function () {
    Project::factory()->create();
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo($project)->create());

    $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
});

test('moving a storage provider between allowed projects', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $provider = StorageProvider::factory()->for($source)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo($source, $target)->create());

    $this->postJson("/api/v1/projects/{$source->id}/moves", ['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $target->id])
        ->assertOk()
        ->assertExactJson(['data' => ['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $target->id]]);

    expect($provider->refresh()->project_id)->toBe($target->id);
});

test('moving into a project outside the key is refused', function () {
    $source = Project::factory()->create();
    $outside = Project::factory()->create();
    $provider = StorageProvider::factory()->for($source)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->limitedTo($source)->create());

    $this->postJson("/api/v1/projects/{$source->id}/moves", ['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $outside->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project_id');
});

test('api calls never change the remembered project', function () {
    $remembered = Project::factory()->create();
    $project = Project::factory()->create();
    $user = actingAsApiKey(ApiKey::factory()->withScopes('projects:write')->create());
    $user->forceFill(['last_project_id' => $remembered->id])->save();

    $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])->assertOk();

    expect($user->refresh()->last_project_id)->toBe($remembered->id);
});
