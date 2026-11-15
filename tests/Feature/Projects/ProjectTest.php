<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('projects page is displayed', function () {
    Project::factory()->create(['name' => 'Production']);

    $this->actingAs(User::factory()->create())
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->has('projects', 1)
            ->where('projects.0.name', 'Production'));
});

test('a project can be created', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('projects.store'), ['name' => '  Staging  '])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', Project::query()->sole()));

    $this->assertDatabaseHas('projects', ['name' => 'Staging']);
});

test('project names must be unique', function () {
    Project::factory()->create(['name' => 'Staging']);

    $this->actingAs(User::factory()->create())
        ->from(route('projects.index'))
        ->post(route('projects.store'), ['name' => 'Staging'])
        ->assertSessionHasErrors(['name' => 'The name has already been taken.'])
        ->assertRedirect(route('projects.index'));

    expect(Project::query()->count())->toBe(1);
});

test('a project can be renamed to its own name or a free one', function () {
    $project = Project::factory()->create(['name' => 'Staging']);

    $this->actingAs(User::factory()->create())
        ->put(route('projects.update', $project), ['name' => 'Preview'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('projects.index'));

    expect($project->refresh()->name)->toBe('Preview');
});

test('an empty project can be deleted', function () {
    Project::factory()->create();
    $project = Project::factory()->create();
    $visitor = User::factory()->create(['last_project_id' => $project->id]);

    $this->actingAs($visitor)
        ->delete(route('projects.destroy', $project))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('projects.index'));

    $this->assertModelMissing($project);
    expect($visitor->refresh()->last_project_id)->toBeNull();
});

test('the last project cannot be deleted', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->from(route('projects.index'))
        ->delete(route('projects.destroy', $project))
        ->assertSessionHasErrors(['project' => 'The last project cannot be deleted.']);

    $this->assertModelExists($project);
});

test('a project that still has resources cannot be deleted', function () {
    Project::factory()->create();
    $project = Project::factory()->create(['name' => 'Busy']);
    Server::factory()->for($project)->create();

    $this->actingAs(User::factory()->create())
        ->from(route('projects.index'))
        ->delete(route('projects.destroy', $project))
        ->assertSessionHasErrors(['project' => 'Busy still has servers, databases, networks or providers. Move or delete them first.']);

    $this->assertModelExists($project);
});
