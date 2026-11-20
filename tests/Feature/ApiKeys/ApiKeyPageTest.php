<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function apiKeyPermissions(array $overrides = []): array
{
    return [
        'projects' => 'none',
        'server-providers' => 'read',
        'dns-providers' => 'none',
        'storage-providers' => 'none',
        'servers' => 'read',
        'databases' => 'write',
        'system' => 'none',
        ...$overrides,
    ];
}

test('the api keys page asks for the password first', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('api-keys.index'))
        ->assertRedirect(route('password.confirm'));
});

test('the page lists the owner keys with their access', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Staging']);
    ApiKey::factory()->ownedBy($owner)->withScopes('databases:write', 'servers:read')->limitedTo($project)->create(['name' => 'staging-ci']);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('api-keys.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('api-keys/index')
            ->has('apiKeys', 1)
            ->where('apiKeys.0.name', 'staging-ci')
            ->where('apiKeys.0.levels.databases', 'write')
            ->where('apiKeys.0.levels.servers', 'read')
            ->where('apiKeys.0.levels.projects', 'none')
            ->where('apiKeys.0.all_projects', false)
            ->where('apiKeys.0.projects.0.name', 'Staging')
            ->has('areas', 7)
            ->has('projects', 1));
});

test('creating a key flashes it once', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('api-keys.store'), ['name' => 'staging-ci', 'expiry' => '90', 'permissions' => apiKeyPermissions(), 'projects' => 'all'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('api-keys.index'))
        ->assertInertiaFlash('apiKey.name', 'staging-ci')
        ->assertInertiaFlash('apiKey.key');

    expect(ApiKey::query()->sole()->scopes)->toBe(['server-providers:read', 'servers:read', 'databases:write']);
});

test('a key can be limited to selected projects', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('api-keys.store'), ['name' => 'ci', 'expiry' => '30', 'permissions' => apiKeyPermissions(), 'projects' => [$project->id]])
        ->assertSessionHasNoErrors();

    expect(ApiKey::query()->sole()->allowedProjectIds())->toBe([$project->id]);
});

test('system cannot be given write access', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('api-keys.store'), ['name' => 'ci', 'expiry' => '30', 'permissions' => apiKeyPermissions(['system' => 'write']), 'projects' => 'all'])
        ->assertSessionHasErrors('permissions.system');
});

test('a key needs at least one area', function () {
    $none = array_fill_keys(array_keys(apiKeyPermissions()), 'none');

    $this->actingAs(User::factory()->create())
        ->post(route('api-keys.store'), ['name' => 'ci', 'expiry' => '30', 'permissions' => $none, 'projects' => 'all'])
        ->assertSessionHasErrors('permissions');
});

test('a key can be revoked from the page', function () {
    $owner = User::factory()->create();
    $key = ApiKey::factory()->ownedBy($owner)->create();

    $this->actingAs($owner)
        ->delete(route('api-keys.destroy', $key))
        ->assertRedirect(route('api-keys.index'));

    expect($key->refresh()->revoked)->toBeTrue();
});

test('another user key cannot be revoked', function () {
    $key = ApiKey::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('api-keys.destroy', $key))
        ->assertNotFound();
});
