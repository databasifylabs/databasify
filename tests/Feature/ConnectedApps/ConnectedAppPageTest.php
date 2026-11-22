<?php

declare(strict_types=1);

use App\Enums\Mcp\AiAgent;
use App\Models\OAuthClient;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OAuthFlow;

test('a guest is sent to the sign-in page', function () {
    User::factory()->create();

    $response = $this->get(route('connected-apps.index'));

    $response->assertRedirectToRoute('login');
});

test('the page lists only approved clients that are not removed', function () {
    $owner = User::factory()->create();
    $approved = OAuthClient::factory()->approvedBy($owner)->withScopes('databases:write', 'mcp:use')->create();
    OAuthClient::factory()->create();
    OAuthClient::factory()->approvedBy($owner)->revoked()->create();

    $response = $this->actingAs($owner)->get(route('connected-apps.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('connected-apps/index')
        ->has('connectedApps', 1)
        ->where('connectedApps.0.id', $approved->id)
        ->where('connectedApps.0.agent', 'claude')
        ->where('connectedApps.0.icon', asset('assets/icons/ai-agents/claude.svg'))
        ->where('connectedApps.0.redirect_host', 'claude.ai')
        ->where('connectedApps.0.levels.databases', 'write')
        ->where('connectedApps.0.levels.servers', 'none'));
});

test('the page offers every agent and the mcp url', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('connected-apps.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('agents', count(AiAgent::cases()))
        ->where('agents.0.value', 'claude-code')
        ->where('mcpUrl', url('/mcp')));
});

test('removing an app revokes it and its access token stops working with 401', function () {
    $owner = User::factory()->create();
    $result = OAuthFlow::connect($this, $owner, ['system' => 'read']);

    $response = $this->actingAs($owner)->delete(route('connected-apps.destroy', $result->client->id));

    $response->assertRedirectToRoute('connected-apps.index');
    $this->assertDatabaseHas('oauth_clients', ['id' => $result->client->id, 'revoked' => true]);
    app('auth')->forgetGuards();
    $this->withToken($result->accessToken)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
});

test('a removed app cannot refresh its token', function () {
    $owner = User::factory()->create();
    $result = OAuthFlow::connect($this, $owner, ['system' => 'read']);
    $this->actingAs($owner)->delete(route('connected-apps.destroy', $result->client->id));

    $response = $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $result->refreshToken,
        'client_id' => $result->client->id,
    ]);

    expect($response->getStatusCode())->toBeIn([400, 401])
        ->and($response->json('access_token'))->toBeNull();
});

test('removing an app of another owner returns 404 and keeps it', function () {
    $client = OAuthClient::factory()->approvedBy(User::factory()->create())->create();

    $response = $this->actingAs(User::factory()->create())->delete(route('connected-apps.destroy', $client->id));

    $response->assertNotFound();
    $this->assertDatabaseHas('oauth_clients', ['id' => $client->id, 'revoked' => false]);
});
