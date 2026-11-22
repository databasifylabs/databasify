<?php

declare(strict_types=1);

use App\Enums\Access\AccessArea;
use App\Models\OAuthClient;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OAuthFlow;

test('a guest is sent to the sign-in page once the owner exists', function () {
    User::factory()->create();
    $flow = OAuthFlow::register($this);

    $response = $flow->authorize();

    $response->assertRedirectToRoute('login');
});

test('the authorize page asks for the password first', function () {
    $flow = OAuthFlow::register($this);
    $this->actingAs(User::factory()->create());

    $response = $flow->authorize();

    $response->assertRedirectToRoute('password.confirm');
});

test('the authorize page shows the client, its agent icon, its redirect host and every area', function () {
    $flow = OAuthFlow::register($this);
    $flow->signIn(User::factory()->create());

    $response = $flow->authorize();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/authorize-connected-app')
        ->where('client.name', 'Claude')
        ->where('client.redirect_host', 'claude.ai')
        ->where('client.icon', asset('assets/icons/ai-agents/claude.svg'))
        ->has('areas', count(AccessArea::cases()))
        ->has('authToken'));
});

test('the authorize page names the redirect host the request uses', function () {
    $flow = OAuthFlow::register($this, 'Claude', [OAuthFlow::REDIRECT, 'https://evil.example/cb']);
    $flow->signIn(User::factory()->create());

    $response = $flow->authorize(redirect: 'https://evil.example/cb');

    $response->assertInertia(fn (Assert $page) => $page->where('client.redirect_host', 'evil.example'));
});

test('approving redirects back with a code and stores the chosen access on the client', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create();
    $flow = OAuthFlow::register($this);
    $flow->signIn($owner);
    $authToken = $flow->authToken();

    $response = $this->withHeader('X-Inertia', 'true')->post('/oauth/authorize', [
        'auth_token' => $authToken,
        'permissions' => OAuthFlow::permissions(['databases' => 'write']),
        'projects' => [$project->id],
    ]);

    $response->assertStatus(409);
    expect((string) $response->headers->get('X-Inertia-Location'))
        ->toStartWith(OAuthFlow::REDIRECT)
        ->toContain('code=')
        ->toContain('state=s1');
    $client = OAuthClient::query()->findOrFail($flow->clientId);
    expect($client)
        ->scopes->toBe(['databases:write', 'mcp:use'])
        ->all_projects->toBeFalse()
        ->approved_at->not->toBeNull()
        ->and($client->owner?->is($owner))->toBeTrue();
    $this->assertDatabaseHas('oauth_client_project', ['client_id' => $client->id, 'project_id' => $project->id]);
});

test('denying redirects back with access_denied and leaves the client unapproved', function () {
    $flow = OAuthFlow::register($this);
    $flow->signIn(User::factory()->create());
    $authToken = $flow->authToken();

    $response = $this->withHeader('X-Inertia', 'true')->delete('/oauth/authorize', ['auth_token' => $authToken]);

    $response->assertStatus(409);
    expect((string) $response->headers->get('X-Inertia-Location'))->toContain('error=access_denied');
    $this->assertDatabaseHas('oauth_clients', ['id' => $flow->clientId, 'approved_at' => null, 'scopes' => null]);
});

test('an approval without any area is refused and can be sent again', function () {
    $flow = OAuthFlow::register($this);
    $flow->signIn(User::factory()->create());
    $authToken = $flow->authToken();
    $flow->approve($authToken, [])->assertSessionHasErrors(['permissions' => 'Give access to at least one area.']);

    $response = $flow->approve($authToken, ['servers' => 'read']);

    expect($flow->codeFrom($response))->not->toBeEmpty();
});

test('an approved client that reconnects skips the page and keeps its stored scopes', function () {
    $flow = OAuthFlow::register($this);
    $flow->signIn(User::factory()->create());
    $flow->exchange($flow->codeFrom($flow->approve($flow->authToken(), ['servers' => 'read'])));

    $response = $flow->authorize('s2');

    $flow->exchange($flow->codeFrom($response));
    expect(OAuthClient::query()->findOrFail($flow->clientId)->tokens()->latest()->firstOrFail()->scopes)
        ->toBe(['servers:read', 'mcp:use']);
});

test('the authorize page shows no icon for an unknown agent', function () {
    $flow = OAuthFlow::register($this, 'My Script', ['http://localhost:9000/callback']);
    $flow->signIn(User::factory()->create());

    $response = $flow->authorize(redirect: 'http://localhost:9000/callback');

    $response->assertInertia(fn (Assert $page) => $page->where('client.icon', null));
});
