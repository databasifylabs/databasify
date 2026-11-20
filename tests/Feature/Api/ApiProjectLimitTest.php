<?php

declare(strict_types=1);

use App\Actions\Projects\DeleteProject;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Server;
use App\Support\Projects\ProjectContext;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:api'])->prefix('api/v1/test')->group(function () {
        Route::get('projects/{project}/servers', fn () => Server::query()->pluck('id'));
        Route::get('servers', fn () => Server::query()->pluck('id'));
        Route::get('servers/{server}', fn (Server $server) => ['context' => ProjectContext::current()->id()]);
    });
});

test('a key limited to one project gets 404 for another project', function () {
    $allowed = Project::factory()->create();
    $other = Project::factory()->create();

    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->limitedTo($allowed)->create());

    $this->getJson("/api/v1/test/projects/{$other->id}/servers")
        ->assertNotFound();
});

test('a key limited to one project lists only that project', function () {
    $allowed = Project::factory()->create();
    $mine = Server::factory()->for($allowed)->create();
    Server::factory()->for(Project::factory())->create();

    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->limitedTo($allowed)->create());

    $this->getJson('/api/v1/test/servers')
        ->assertExactJson([$mine->id]);
});

test('a key limited to one project gets 404 for a server in another project', function () {
    $allowed = Project::factory()->create();
    $server = Server::factory()->for(Project::factory())->create();

    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->limitedTo($allowed)->create());

    $this->getJson("/api/v1/test/servers/{$server->id}")
        ->assertNotFound();
});

test('an item route switches the context to the item project', function () {
    $server = Server::factory()->for(Project::factory())->create();

    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson("/api/v1/test/servers/{$server->id}")
        ->assertExactJson(['context' => $server->project_id]);
});

test('an all-projects key reaches every project', function () {
    Server::factory()->for(Project::factory())->create();
    Server::factory()->for(Project::factory())->create();

    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->create());

    $this->getJson('/api/v1/test/servers')
        ->assertJsonCount(2);
});

test('a key whose only project was deleted sees nothing', function () {
    Server::factory()->for(Project::factory())->create();
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->limitedTo($project)->create());
    app(DeleteProject::class)->handle($project);

    $this->getJson('/api/v1/test/servers')
        ->assertExactJson([]);
});
