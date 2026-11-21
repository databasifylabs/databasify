<?php

declare(strict_types=1);

use App\Jobs\Servers\LaunchProviderServerJob;
use App\Models\ApiKey;
use App\Models\Server;
use App\Models\ServerProvider;
use Illuminate\Support\Facades\Queue;

test('a server is ordered from a provider', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    $provider = ServerProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

    $this->postJson("/api/v1/server-providers/{$provider->id}/servers", [
        'name' => 'db-1',
        'region' => 'nyc3',
        'plan' => 's-2vcpu-4gb',
        'os' => 'ubuntu-24.04',
    ])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'creating')
        ->assertJsonPath('data.server_provider_id', $provider->id)
        ->assertJsonPath('data.project_id', $provider->project_id);

    Queue::assertPushed(LaunchProviderServerJob::class);
});

test('an operating system the provider does not offer is refused', function () {
    Queue::fake([LaunchProviderServerJob::class]);
    $provider = ServerProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('server-providers:write')->create());

    $this->postJson("/api/v1/server-providers/{$provider->id}/servers", [
        'name' => 'db-1',
        'region' => 'nyc3',
        'plan' => 's-2vcpu-4gb',
        'os' => 'debian-12',
    ])->assertUnprocessable()->assertJsonValidationErrors('os');

    expect(Server::query()->exists())->toBeFalse();
});

test('ordering needs server-providers:write', function () {
    $provider = ServerProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('servers:write')->create());

    $this->postJson("/api/v1/server-providers/{$provider->id}/servers", ['name' => 'db-1'])->assertForbidden();
});
