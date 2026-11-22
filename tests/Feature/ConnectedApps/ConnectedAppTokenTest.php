<?php

declare(strict_types=1);

use App\Actions\Projects\DeleteProject;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\OAuthClient;
use App\Models\Project;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\OAuthFlow;
use Tests\Support\OAuthFlowResult;

function latestKeyOf(OAuthClient $client): ApiKey
{
    return ApiKey::query()->where('client_id', $client->id)->latest()->firstOrFail();
}

function refreshAgentToken(OAuthFlowResult $result): TestResponse
{
    test()->travel(1)->minutes();

    return test()->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $result->refreshToken,
        'client_id' => $result->client->id,
    ]);
}

test('an agent token gets the approved scopes and project limit', function () {
    $project = Project::factory()->create();

    $result = OAuthFlow::connect($this, User::factory()->create(), ['databases' => 'read'], [$project->id]);

    expect(latestKeyOf($result->client))
        ->scopes->toBe(['databases:read', 'mcp:use'])
        ->all_projects->toBeFalse()
        ->allowedProjectIds()->toBe([$project->id]);
});

test('a refreshed agent token keeps the approved scopes and project limit', function () {
    $project = Project::factory()->create();
    $result = OAuthFlow::connect($this, User::factory()->create(), ['databases' => 'read'], [$project->id]);

    $response = refreshAgentToken($result);

    $response->assertOk();
    expect(ApiKey::query()->where('client_id', $result->client->id)->count())->toBe(2)
        ->and(latestKeyOf($result->client))
        ->scopes->toBe(['databases:read', 'mcp:use'])
        ->allowedProjectIds()->toBe([$project->id]);
});

test('an agent token lists only the databases of its allowed project', function () {
    $allowed = Project::factory()->create();
    $mine = Database::factory()->for($allowed)->create();
    Database::factory()->for(Project::factory())->create();
    $result = OAuthFlow::connect($this, User::factory()->create(), ['databases' => 'read'], [$allowed->id]);
    app('auth')->forgetGuards();

    $response = $this->withToken($result->accessToken)->getJson('/api/v1/databases');

    $response->assertOk()->assertJsonPath('data.*.id', [$mine->id]);
});

test('an agent token returns 403 for an area it was not given', function () {
    $result = OAuthFlow::connect($this, User::factory()->create(), ['databases' => 'read']);
    app('auth')->forgetGuards();

    $response = $this->withToken($result->accessToken)->getJson('/api/v1/servers');

    $response->assertForbidden();
});

test('a limited agent whose only project is deleted lists nothing after a refresh', function () {
    $project = Project::factory()->create();
    Database::factory()->for(Project::factory())->create();
    $result = OAuthFlow::connect($this, User::factory()->create(), ['databases' => 'read'], [$project->id]);
    app(DeleteProject::class)->handle($project);
    $refreshed = refreshAgentToken($result)->assertOk();
    app('auth')->forgetGuards();

    $response = $this->withToken((string) $refreshed->json('access_token'))->getJson('/api/v1/databases');

    $response->assertOk()->assertJsonPath('data', []);
});
