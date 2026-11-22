<?php

declare(strict_types=1);

use App\Actions\Projects\DeleteProject;
use App\Models\ApiKey;
use App\Models\OAuthClient;
use App\Models\Project;
use App\Models\User;
use Laravel\Passport\RefreshToken;
use Tests\Support\OAuthFlow;

test('the authorization server metadata points agents at oauth with pkce', function () {
    $response = $this->getJson('/.well-known/oauth-authorization-server');

    $response->assertOk()
        ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
        ->assertJsonPath('token_endpoint', url('/oauth/token'))
        ->assertJsonPath('registration_endpoint', url('/oauth/register'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);
});

test('client registration returns 429 after ten registrations in a minute', function () {
    $registration = ['client_name' => 'Claude', 'redirect_uris' => [OAuthFlow::REDIRECT]];
    collect(range(1, 10))->each(fn () => $this->postJson('/oauth/register', $registration)->assertCreated());

    $response = $this->postJson('/oauth/register', $registration);

    $response->assertTooManyRequests();
    expect(OAuthClient::query()->count())->toBe(10);
});

test('the device code flow returns 404', function () {
    $response = $this->postJson('/oauth/device/code', ['client_id' => 'any']);

    $response->assertNotFound();
});

test('an agent access token lasts an hour and its refresh token thirty days', function () {
    $result = OAuthFlow::connect($this, User::factory()->create(), ['system' => 'read']);

    $token = ApiKey::query()->where('client_id', $result->client->id)->sole();
    $refreshToken = RefreshToken::query()->where('access_token_id', $token->id)->sole();
    expect($token->expires_at?->diffInSeconds(now()->addHour(), absolute: true))->toBeLessThan(5)
        ->and($refreshToken->expires_at?->diffInSeconds(now()->addDays(30), absolute: true))->toBeLessThan(5);
});

test('deleting a project removes it from the clients limited to it', function () {
    Project::factory()->create();
    $project = Project::factory()->create();
    $client = OAuthClient::factory()->approvedBy(User::factory()->create())->limitedTo($project)->create();

    app(DeleteProject::class)->handle($project);

    $this->assertDatabaseMissing('oauth_client_project', ['client_id' => $client->id, 'project_id' => $project->id]);
});
