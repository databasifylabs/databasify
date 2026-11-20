<?php

declare(strict_types=1);

use App\Actions\Api\CreateApiKey;
use App\DTOs\Api\NewApiKey;
use App\Enums\Api\ApiKeyExpiry;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageProvider;
use App\Models\User;
use Laravel\Passport\Passport;

function actingWithProjectKey(array $scopes, ?array $projectIds = null): User
{
    $user = User::factory()->create();
    $key = app(CreateApiKey::class)->handle($user, new NewApiKey('ci', ApiKeyExpiry::Days30, $scopes, $projectIds));
    test()->withToken($key->plainTextKey);

    return $user;
}

test('listing projects shows only the key projects', function () {
    $mine = Project::factory()->create(['name' => 'Staging']);
    Project::factory()->create(['name' => 'Production']);
    actingWithProjectKey(['projects:read'], [$mine->id]);

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Staging')
        ->assertJsonStructure(['data' => [['id', 'name', 'servers_count', 'databases_count', 'created_at']], 'links', 'meta']);
});

test('creating a project needs projects:write and an all-projects key', function () {
    Passport::actingAs(User::factory()->create(), ['projects:read'], 'api');
    $this->postJson('/api/v1/projects', ['name' => 'Read only'])->assertForbidden();

    app('auth')->forgetGuards();
    actingWithProjectKey(['projects:write'], [Project::factory()->create()->id]);
    $this->postJson('/api/v1/projects', ['name' => 'Limited'])->assertForbidden();

    app('auth')->forgetGuards();
    actingWithProjectKey(['projects:write']);
    $this->postJson('/api/v1/projects', ['name' => 'Analytics'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Analytics');
});

test('renaming a project', function () {
    $project = Project::factory()->create();
    actingWithProjectKey(['projects:write'], [$project->id]);

    $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

test('renaming a project outside the key is not found', function () {
    $other = Project::factory()->create();
    actingWithProjectKey(['projects:write'], [Project::factory()->create()->id]);

    $this->patchJson("/api/v1/projects/{$other->id}", ['name' => 'Renamed'])->assertNotFound();
});

test('deleting an empty project', function () {
    Project::factory()->create();
    $project = Project::factory()->create();
    actingWithProjectKey(['projects:write']);

    $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

    expect(Project::query()->find($project->id))->toBeNull();
});

test('deleting a project that still has resources is refused', function () {
    $project = Project::factory()->create();
    Server::factory()->for($project)->create();
    Project::factory()->create();
    actingWithProjectKey(['projects:write']);

    $this->deleteJson("/api/v1/projects/{$project->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project');
});

test('a limited key cannot delete a project', function () {
    Project::factory()->create();
    $project = Project::factory()->create();
    actingWithProjectKey(['projects:write'], [$project->id]);

    $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
});

test('moving a storage provider between allowed projects', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $provider = StorageProvider::factory()->for($source)->create();
    actingWithProjectKey(['projects:write'], [$source->id, $target->id]);

    $this->postJson("/api/v1/projects/{$source->id}/moves", ['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $target->id])
        ->assertOk()
        ->assertExactJson(['data' => ['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $target->id]]);

    expect($provider->refresh()->project_id)->toBe($target->id);
});

test('moving into a project outside the key is refused', function () {
    $source = Project::factory()->create();
    $outside = Project::factory()->create();
    $provider = StorageProvider::factory()->for($source)->create();
    actingWithProjectKey(['projects:write'], [$source->id]);

    $this->postJson("/api/v1/projects/{$source->id}/moves", ['type' => 'storage-provider', 'id' => $provider->id, 'project_id' => $outside->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project_id');
});

test('api calls never change the remembered project', function () {
    $remembered = Project::factory()->create();
    $project = Project::factory()->create();
    $user = actingWithProjectKey(['projects:write']);
    $user->forceFill(['last_project_id' => $remembered->id])->save();

    $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])->assertOk();

    expect($user->refresh()->last_project_id)->toBe($remembered->id);
});
