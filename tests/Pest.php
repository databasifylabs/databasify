<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class, WithCachedConfig::class, WithCachedRoutes::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
    })
    ->in('Feature');

function actingAsApiKey(ApiKey $key): User
{
    $user = User::query()->findOrFail($key->user_id);
    $user->withAccessToken(new AccessToken([
        'oauth_access_token_id' => $key->id,
        'oauth_client_id' => $key->client_id,
        'oauth_user_id' => $user->id,
        'oauth_scopes' => $key->scopes,
    ]));

    app('auth')->guard('api')->setUser($user);
    app('auth')->shouldUse('api');

    return $user;
}

/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function mcpRpc(string $method, array $params = [], ?string $token = null): array
{
    $response = test()->withHeaders($token === null ? [] : ['Authorization' => "Bearer {$token}"])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

    if (! $response->baseResponse instanceof StreamedResponse) {
        return $response->json() ?? [];
    }

    $events = array_values(preg_grep('/^data: /', explode("\n", $response->streamedContent())) ?: []);

    return json_decode(substr((string) end($events), 6), true) ?? [];
}

/**
 * @return list<string>
 */
function mcpToolNames(?string $token = null): array
{
    return array_column(mcpRpc('tools/list', token: $token)['result']['tools'] ?? [], 'name');
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function mcpCall(string $tool, array $arguments = [], ?string $token = null): array
{
    if (in_array($tool, mcpToolNames($token), true)) {
        return mcpRpc('tools/call', ['name' => $tool, 'arguments' => (object) $arguments], $token);
    }

    $response = mcpRpc('tools/call', ['name' => 'execute_tools', 'arguments' => ['calls' => [['name' => $tool, 'arguments' => (object) $arguments]]]], $token);
    $text = (string) ($response['result']['content'][0]['text'] ?? '');
    $call = json_decode($text, true)['results'][0] ?? ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];

    return ['result' => ['content' => $call['content'] ?? [], 'isError' => $call['isError'] ?? true]];
}

/**
 * @param  array<string, mixed>  $response
 */
function mcpData(array $response): mixed
{
    expect($response['result']['isError'] ?? null)->toBeFalse(json_encode($response) ?: '');

    return json_decode((string) ($response['result']['content'][0]['text'] ?? ''), true);
}

/**
 * @param  array<string, mixed>  $response
 */
function mcpError(array $response): string
{
    expect($response['result']['isError'] ?? null)->toBeTrue(json_encode($response) ?: '');

    return (string) ($response['result']['content'][0]['text'] ?? '');
}
