<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Database\Factories\ProjectFactory;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('index', function () {
    it('lists projects by name with their resource counts', function () {
        $default = ProjectFactory::defaultProject();
        $default->update(['name' => 'Zeta']);
        Server::factory()->create();
        Project::factory()->create(['name' => 'Alpha']);

        $this->get('/projects')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/index', false)
            ->where('projects.0.name', 'Alpha')
            ->where('projects.1.name', 'Zeta')
            ->where('projects.1.servers_count', 1));
    });
});

describe('store', function () {
    it('creates a project and opens its dashboard', function () {
        $response = $this->post('/projects', ['name' => ' Staging ']);

        $project = Project::query()->sole();
        $response->assertRedirect("/{$project->id}/dashboard");
        expect($project->name)->toBe('Staging');
    });

    it('refuses a duplicate name', function () {
        Project::factory()->create(['name' => 'Staging']);

        $this->post('/projects', ['name' => 'Staging'])->assertSessionHasErrors(['name' => 'The name has already been taken.']);
    });
});

describe('update', function () {
    it('renames a project', function () {
        $project = Project::factory()->create(['name' => 'Old']);

        $this->put("/projects/{$project->id}", ['name' => 'New'])->assertRedirect('/projects');

        expect($project->fresh()?->name)->toBe('New');
    });

    it('keeps its own name but refuses the name of another project', function () {
        $project = Project::factory()->create(['name' => 'Old']);
        Project::factory()->create(['name' => 'Taken']);

        $this->put("/projects/{$project->id}", ['name' => 'Old'])->assertSessionHasNoErrors();
        $this->put("/projects/{$project->id}", ['name' => 'Taken'])->assertSessionHasErrors(['name' => 'The name has already been taken.']);
    });
});

describe('destroy', function () {
    it('deletes an empty project', function () {
        ProjectFactory::defaultProject();
        $empty = Project::factory()->create();

        $this->delete("/projects/{$empty->id}")->assertRedirect('/projects');

        expect($empty->fresh())->toBeNull();
    });

    it('refuses to delete a project that still has resources', function () {
        $project = Project::factory()->create(['name' => 'Busy']);
        Project::factory()->create();
        Server::factory()->for($project)->create();

        $this->delete("/projects/{$project->id}")->assertSessionHasErrors(['project' => 'Busy still has servers, databases, networks or providers. Move or delete them first.']);

        expect($project->fresh())->not->toBeNull();
    });

    it('refuses to delete the last project', function () {
        $project = ProjectFactory::defaultProject();

        $this->delete("/projects/{$project->id}")->assertSessionHasErrors(['project' => 'The last project cannot be deleted.']);
    });
});

describe('current project', function () {
    it('remembers the last visited project and falls back to it', function () {
        ProjectFactory::defaultProject();
        $last = Project::factory()->create(['name' => 'Zulu']);

        $this->get("/{$last->id}/dashboard")->assertOk();

        expect($this->user->fresh()?->last_project_id)->toBe($last->id);
        $this->get('/')->assertRedirect("/{$last->id}/dashboard");
        $this->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currentProject', ['id' => $last->id, 'name' => 'Zulu'])
            ->has('projects', 2));
    });

    it('falls back to the first project by name', function () {
        $beta = Project::factory()->create(['name' => 'Beta']);
        $alpha = Project::factory()->create(['name' => 'Alpha']);

        $this->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currentProject', ['id' => $alpha->id, 'name' => 'Alpha'])
            ->where('projects', [['id' => $alpha->id, 'name' => 'Alpha'], ['id' => $beta->id, 'name' => 'Beta']]));
    });

    it('sends home to the projects page when there is no project', function () {
        $this->get('/')->assertRedirect('/projects');
    });
});
