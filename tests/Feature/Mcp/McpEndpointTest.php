<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Database;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Tests\Support\OAuthFlow;

/**
 * @param  list<array{name: string, arguments: array<string, mixed>}>  $calls
 * @return list<array{isError: bool, content: list<array{text: string}>}>
 */
function executeTools(array $calls): array
{
    $response = mcpRpc('tools/call', ['name' => 'execute_tools', 'arguments' => ['calls' => $calls]]);

    return json_decode((string) $response['result']['content'][0]['text'], true)['results'];
}

test('a request without a token returns 401 with the resource metadata header', function () {
    $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

    $response->assertUnauthorized();
    expect((string) $response->headers->get('WWW-Authenticate'))->toContain('resource_metadata');
});

test('the tool list holds only the tools the token may use', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('projects:read')->create());

    $names = mcpToolNames();

    expect($names)->toContain('list_projects')->not->toContain('create_project');
});

test('execute_tools refuses a write tool for a read-only token', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('projects:read')->create());

    $results = executeTools([['name' => 'create_project', 'arguments' => ['name' => 'Sneaky']]]);

    expect($results[0]['isError'])->toBeTrue();
    $this->assertDatabaseMissing('projects', ['name' => 'Sneaky']);
});

test('a key limited to one project lists only that project', function () {
    $allowed = Project::factory()->create();
    Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('projects:read')->limitedTo($allowed)->create());

    $response = mcpCall('list_projects');

    expect(array_column(mcpData($response)['data'], 'id'))->toBe([$allowed->id]);
});

test('each tool in one execute_tools call starts without the project of the tool before it', function () {
    $first = Database::factory()->for(Project::factory())->create();
    $second = Server::factory()->for(Project::factory())->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read', 'servers:read')->create());

    $results = executeTools([
        ['name' => 'list_database_nodes', 'arguments' => ['database' => $first->id]],
        ['name' => 'get_server', 'arguments' => ['server' => $second->id]],
    ]);

    expect($results[1]['isError'])->toBeFalse()
        ->and(json_decode($results[1]['content'][0]['text'], true)['id'])->toBe($second->id);
});

test('a limited key stays limited across the tools of one execute_tools call', function () {
    $allowed = Project::factory()->create();
    $mine = Server::factory()->for($allowed)->create();
    $other = Server::factory()->for(Project::factory())->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:read')->limitedTo($allowed)->create());

    $results = executeTools([
        ['name' => 'get_server', 'arguments' => ['server' => $mine->id]],
        ['name' => 'get_server', 'arguments' => ['server' => $other->id]],
    ]);

    expect($results[0]['isError'])->toBeFalse()
        ->and($results[1]['isError'])->toBeTrue()
        ->and($results[1]['content'][0]['text'])->toContain('No server with this id.');
});

test('an api key can call a tool', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('system:read')->create());

    $response = mcpCall('get_system');

    expect(mcpData($response))->toHaveKey('version', config('app.version'));
});

test('an agent token from oauth can call a tool', function () {
    $result = OAuthFlow::connect($this, User::factory()->create(), ['system' => 'read']);
    app('auth')->forgetGuards();

    $response = mcpCall('get_system', token: $result->accessToken);

    expect(mcpData($response))->toHaveKey('version', config('app.version'));
});
