<?php

declare(strict_types=1);

use App\Actions\Api\CreateApiKey;
use App\Actions\Projects\DeleteProject;
use App\DTOs\Api\NewApiKey;
use App\Enums\Api\ApiKeyExpiry;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Support\Projects\ProjectContext;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:api'])->prefix('api/v1/test')->group(function () {
        Route::get('projects/{project}/servers', fn () => Server::query()->pluck('id'));
        Route::get('servers', fn () => Server::query()->pluck('id'));
        Route::get('servers/{server}', fn (Server $server) => ['context' => ProjectContext::current()->id()]);
    });
});

function limitedApiKey(?array $projectIds): string
{
    return app(CreateApiKey::class)->handle(User::factory()->create(), new NewApiKey('ci', ApiKeyExpiry::Days30, ['servers:read'], $projectIds))->plainTextKey;
}

test('a key limited to one project gets 404 for another project', function () {
    $allowed = Project::factory()->create();
    $other = Project::factory()->create();

    $this->withToken(limitedApiKey([$allowed->id]))
        ->getJson("/api/v1/test/projects/{$other->id}/servers")
        ->assertNotFound();
});

test('a key limited to one project lists only that project', function () {
    $allowed = Project::factory()->create();
    $mine = Server::factory()->for($allowed)->create();
    Server::factory()->for(Project::factory())->create();

    $this->withToken(limitedApiKey([$allowed->id]))
        ->getJson('/api/v1/test/servers')
        ->assertExactJson([$mine->id]);
});

test('a key limited to one project gets 404 for a server in another project', function () {
    $allowed = Project::factory()->create();
    $server = Server::factory()->for(Project::factory())->create();

    $this->withToken(limitedApiKey([$allowed->id]))
        ->getJson("/api/v1/test/servers/{$server->id}")
        ->assertNotFound();
});

test('an item route switches the context to the item project', function () {
    $server = Server::factory()->for(Project::factory())->create();

    $this->withToken(limitedApiKey(null))
        ->getJson("/api/v1/test/servers/{$server->id}")
        ->assertExactJson(['context' => $server->project_id]);
});

test('an all-projects key reaches every project', function () {
    $servers = Server::factory()->count(2)->sequence(
        fn () => ['project_id' => Project::factory()->create()->id],
    )->create();

    $this->withToken(limitedApiKey(null))
        ->getJson('/api/v1/test/servers')
        ->assertJsonCount(2);

    expect($servers)->toHaveCount(2);
});

test('a key whose only project was deleted sees nothing', function () {
    Server::factory()->for(Project::factory())->create();
    $project = Project::factory()->create();
    $key = limitedApiKey([$project->id]);
    app(DeleteProject::class)->handle($project);

    $this->withToken($key)
        ->getJson('/api/v1/test/servers')
        ->assertExactJson([]);
});
