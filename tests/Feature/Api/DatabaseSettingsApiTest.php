<?php

declare(strict_types=1);

use App\Engines\Data\EngineField;
use App\Jobs\Databases\ApplySettingsJob;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\ApiKey;
use App\Models\Database;
use App\Models\DnsZone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('database config is saved and applied', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->create(['config_fields' => [
        new EngineField('max_connections', 'Max connections', 'Most clients at once', 'integer', '100', [], null, 10, 1000, null, true, true),
    ]]);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->putJson("/api/v1/databases/{$database->id}/config", ['config' => ['max_connections' => '250']])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'updating')
        ->assertJsonPath('data.config.max_connections', '250');
});

test('database config values outside the engine range are refused', function () {
    $database = Database::factory()->create(['config_fields' => [
        new EngineField('max_connections', 'Max connections', 'Most clients at once', 'integer', '100', [], null, 10, 1000, null, true, true),
    ]]);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->putJson("/api/v1/databases/{$database->id}/config", ['config' => ['max_connections' => '5']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['config.max_connections' => 'Max connections must be between 10 and 1000.']);
});

test('turning persistence off for a key value engine is applied', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->engine('valkey', '9')->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->putJson("/api/v1/databases/{$database->id}/persistence", ['persistence' => 'off'])
        ->assertAccepted()
        ->assertJsonPath('data.persistence', 'off');
});

test('engines that always persist refuse a persistence change', function () {
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->putJson("/api/v1/databases/{$database->id}/persistence", ['persistence' => 'off'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['persistence' => 'PostgreSQL always keeps its data on disk.']);
});

test('the maintenance window moves', function () {
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->putJson("/api/v1/databases/{$database->id}/maintenance", ['day' => 6, 'hour' => 23])->assertOk();

    expect($database->refresh())->maintenance_day->toBe(6)->maintenance_hour->toBe(23);
});

test('the maintenance window must be a real day and hour', function () {
    $database = Database::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->putJson("/api/v1/databases/{$database->id}/maintenance", ['day' => 7, 'hour' => 24])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['day', 'hour']);
});

test('a database moves to a domain and is redeployed', function () {
    Queue::fake([UpgradeDatabaseJob::class]);
    Http::fake(['https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response(['result' => []])]);
    $database = Database::factory()->create();
    $zone = DnsZone::factory()->create(['external_id' => 'zone-1']);
    actingAsApiKey(ApiKey::factory()->withScopes('databases:write')->create());

    $this->putJson("/api/v1/databases/{$database->id}/domain", ['dns_zone_id' => $zone->id])
        ->assertAccepted()
        ->assertJsonPath('data.dns_zone_id', $zone->id)
        ->assertJsonPath('data.status', 'redeploying');
});
