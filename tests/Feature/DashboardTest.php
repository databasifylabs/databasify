<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    User::factory()->create();
    $project = Project::factory()->create();

    $this->get(route('dashboard', $project))->assertRedirect(route('login'));
});

test('unverified users are redirected to the verification notice', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard', $project))
        ->assertRedirect(route('verification.notice'));
});

test('authenticated users can visit the project dashboard', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard'));
});

test('visiting a project dashboard remembers it as the last project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $this->actingAs($user)->get(route('dashboard', $project));

    expect($user->refresh()->last_project_id)->toBe($project->id);
});

test('home redirects to the last visited project', function () {
    Project::factory()->create(['name' => 'Alpha']);
    $last = Project::factory()->create(['name' => 'Zulu']);
    $user = User::factory()->create(['last_project_id' => $last->id]);

    $this->actingAs($user)->get(route('home'))->assertRedirect(route('dashboard', $last));
});

test('home redirects to the first project by name when none was visited', function () {
    Project::factory()->create(['name' => 'Zulu']);
    $first = Project::factory()->create(['name' => 'Alpha']);

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard', $first));
});

test('home redirects to the project list when no project exists', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('projects.index'));
});

test('an unknown project returns 404', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard', '0199a0c4-0000-7000-8000-000000000000'))
        ->assertNotFound();
});
