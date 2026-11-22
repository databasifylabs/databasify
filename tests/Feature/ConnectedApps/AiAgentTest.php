<?php

declare(strict_types=1);

use App\Enums\Mcp\AiAgent;
use App\Models\OAuthClient;

test('a client named claude code is claude code, not claude', function () {
    $client = OAuthClient::factory()->named('Claude Code (databasify)', 'http://localhost:51234/callback')->make();

    $agent = AiAgent::forClient($client);

    expect($agent)->toBe(AiAgent::ClaudeCode);
});

test('an agent is recognised by its documented redirect', function (string $redirect, AiAgent $agent) {
    $client = OAuthClient::factory()->named('MCP Client', $redirect)->make();

    expect(AiAgent::forClient($client))->toBe($agent);
})->with([
    'claude' => ['https://claude.ai/api/mcp/auth_callback', AiAgent::Claude],
    'chatgpt' => ['https://chatgpt.com/connector_platform_oauth_redirect', AiAgent::ChatGpt],
    'cursor' => ['https://www.cursor.com/agents/mcp/oauth/callback', AiAgent::Cursor],
]);

test('an agent is recognised by its client name', function (string $name, AiAgent $agent) {
    $client = OAuthClient::factory()->named($name, 'http://127.0.0.1:33418/callback')->make();

    expect(AiAgent::forClient($client))->toBe($agent);
})->with([
    'vs code' => ['Visual Studio Code', AiAgent::VsCode],
    'gemini cli' => ['Gemini CLI MCP Client', AiAgent::GeminiCli],
    'codex' => ['Codex', AiAgent::Codex],
    'zed' => ['Zed', AiAgent::Zed],
]);

test('a client with one foreign redirect is not trusted as the agent', function () {
    $client = OAuthClient::factory()->state(['name' => 'Helper', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback', 'https://evil.example/cb']])->make();

    $agent = AiAgent::forClient($client);

    expect($agent)->toBeNull();
});

test('an unknown client matches no agent', function () {
    $client = OAuthClient::factory()->named('My Script', 'http://localhost:9000/callback')->make();

    $agent = AiAgent::forClient($client);

    expect($agent)->toBeNull();
});
