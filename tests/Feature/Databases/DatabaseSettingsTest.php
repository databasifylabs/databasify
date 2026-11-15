<?php

declare(strict_types=1);

use App\DTOs\Databases\EngineImage;
use App\Engines\Data\EngineField;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplySettingsJob;
use App\Jobs\Databases\UpgradeDatabaseJob;
use App\Models\Database;
use App\Models\DnsProvider;
use App\Models\DnsZone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function databaseWithConnectionLimit(): Database
{
    return Database::factory()->create(['config_fields' => [
        new EngineField('max_connections', 'Max connections', 'Most clients at once', 'integer', '100', [], null, 10, 1000, null, true, true),
    ]]);
}

test('database config is saved and applied', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = databaseWithConnectionLimit();

    $this->actingAs(User::factory()->create())
        ->put(route('databases.config', [$database->project_id, $database]), ['config' => ['max_connections' => '250']])
        ->assertSessionHasNoErrors();

    expect($database->refresh())
        ->status->toBe(DatabaseStatus::Updating)
        ->settings->config->toBe(['max_connections' => '250']);
    Queue::assertPushed(ApplySettingsJob::class);
});

test('database config values outside the engine range are refused', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = databaseWithConnectionLimit();

    $this->actingAs(User::factory()->create())
        ->put(route('databases.config', [$database->project_id, $database]), ['config' => ['max_connections' => '5']])
        ->assertSessionHasErrors(['config.max_connections' => 'Max connections must be between 10 and 1000.']);

    expect($database->refresh()->settings->config)->toBe([]);
    Queue::assertNotPushed(ApplySettingsJob::class);
});

test('database config keys the engine does not offer are refused', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = databaseWithConnectionLimit();

    $this->actingAs(User::factory()->create())
        ->put(route('databases.config', [$database->project_id, $database]), ['config' => ['shared_preload_libraries' => 'evil']])
        ->assertSessionHasErrors('config');

    Queue::assertNotPushed(ApplySettingsJob::class);
});

test('database settings are not changed while the image runs an unsupported sdk', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->create(['image' => new EngineImage('ghcr.io/databasifylabs/engine-postgres', 'sha256:'.str_repeat('c', 64), '18.0', '0.1.0')]);

    $this->actingAs(User::factory()->create())
        ->put(route('databases.config', [$database->project_id, $database]), ['config' => []])
        ->assertSessionHasErrors('database');

    Queue::assertNotPushed(ApplySettingsJob::class);
});

test('turning persistence off for a key value engine disables the append only file', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->engine('valkey', '9')->create();

    $this->actingAs(User::factory()->create())
        ->put(route('databases.persistence', [$database->project_id, $database]), ['persistence' => 'off'])
        ->assertSessionHasNoErrors();

    expect($database->refresh()->settings->config)->toBe(['appendonly' => 'no']);
    Queue::assertPushed(ApplySettingsJob::class);
});

test('engines that always persist refuse a persistence change', function () {
    Queue::fake([ApplySettingsJob::class]);
    $database = Database::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('databases.persistence', [$database->project_id, $database]), ['persistence' => 'off'])
        ->assertSessionHasErrors(['persistence' => 'PostgreSQL always keeps its data on disk.']);

    Queue::assertNotPushed(ApplySettingsJob::class);
});

test('the maintenance window can be moved', function () {
    $database = Database::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('databases.maintenance', [$database->project_id, $database]), ['day' => 6, 'hour' => 23])
        ->assertSessionHasNoErrors();

    expect($database->refresh())
        ->maintenance_day->toBe(6)
        ->maintenance_hour->toBe(23);
});

test('the maintenance window must be a real day and hour', function () {
    $database = Database::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('databases.maintenance', [$database->project_id, $database]), ['day' => 7, 'hour' => 24])
        ->assertSessionHasErrors(['day', 'hour']);
});

test('a database moves to a domain and is redeployed', function () {
    Queue::fake([UpgradeDatabaseJob::class]);
    Http::fake(['https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response(['result' => []])]);
    $database = Database::factory()->create();
    $zone = DnsZone::factory()->create(['external_id' => 'zone-1']);

    $this->actingAs(User::factory()->create())
        ->put(route('databases.domain', [$database->project_id, $database]), ['dns_zone_id' => $zone->id])
        ->assertSessionHasNoErrors();

    expect($database->refresh())
        ->dns_zone_id->toBe($zone->id)
        ->status->toBe(DatabaseStatus::Redeploying);
    Queue::assertPushed(UpgradeDatabaseJob::class);
});

test('a domain whose hostname has foreign records is refused', function () {
    Queue::fake([UpgradeDatabaseJob::class]);
    Http::fake(['https://api.cloudflare.com/client/v4/zones/zone-1/dns_records*' => Http::response(['result' => [
        ['id' => 'rec-1', 'content' => '198.51.100.9', 'comment' => 'added by hand'],
    ]])]);
    $database = Database::factory()->create(['name' => 'orders']);
    $zone = DnsZone::factory()->create(['name' => 'example.com', 'external_id' => 'zone-1']);

    $this->actingAs(User::factory()->create())
        ->put(route('databases.domain', [$database->project_id, $database]), ['dns_zone_id' => $zone->id])
        ->assertSessionHasErrors(['dns_zone_id' => 'orders.db.example.com already has DNS records Databasify didn\'t create. Remove them or pick another name.']);

    expect($database->refresh()->dns_zone_id)->toBeNull();
    Queue::assertNotPushed(UpgradeDatabaseJob::class);
});

test('a database cannot move to the domain it already uses', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $database = Database::factory()->create(['name' => 'orders', 'dns_zone_id' => $zone->id]);

    $this->actingAs(User::factory()->create())
        ->put(route('databases.domain', [$database->project_id, $database]), ['dns_zone_id' => $zone->id])
        ->assertSessionHasErrors(['dns_zone_id' => 'orders already uses example.com.']);
});

test('a domain of another project cannot be used', function () {
    $database = Database::factory()->create();
    $foreignZone = DnsZone::factory()->for(DnsProvider::factory()->for(Project::factory()), 'provider')->create();

    $this->actingAs(User::factory()->create())
        ->put(route('databases.domain', [$database->project_id, $database]), ['dns_zone_id' => $foreignZone->id])
        ->assertNotFound();

    expect($database->refresh()->dns_zone_id)->toBeNull();
});
