<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Database;
use App\Models\PointInTimeRecovery;
use App\Models\Project;
use App\Models\StorageProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const API_BUCKET_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

function fakeApiBucket(): void
{
    Http::fake(['https://s3.example.com/archives/*' => fn (Request $request) => match ($request->method()) {
        'GET' => Http::response('databasify'),
        'DELETE' => Http::response(status: 204),
        default => Http::response(),
    }]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiBucketForm(array $overrides = []): array
{
    return [
        'name' => 'backups',
        'endpoint' => 'https://s3.example.com',
        'region' => 'us-east-1',
        'bucket' => 'archives',
        'path_style' => true,
        'access_key_id' => 'AKIDEXAMPLE',
        'secret_access_key' => API_BUCKET_SECRET,
        ...$overrides,
    ];
}

test('storage providers never return the secret key', function () {
    fakeApiBucket();
    $project = Project::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:write')->create());

    $created = $this->postJson("/api/v1/projects/{$project->id}/storage-providers", apiBucketForm())
        ->assertCreated()
        ->assertJsonPath('data.access_key_id', 'AKIDEXAMPLE')
        ->assertJsonMissingPath('data.secret_access_key');
    expect($created->getContent())->not->toContain(API_BUCKET_SECRET);

    $updated = $this->putJson('/api/v1/storage-providers/'.$created->json('data.id'), apiBucketForm(['secret_access_key' => 'rotated-'.API_BUCKET_SECRET]))
        ->assertOk()
        ->assertJsonMissingPath('data.secret_access_key');
    expect($updated->getContent())->not->toContain(API_BUCKET_SECRET);
});

test('storage providers are listed per project', function () {
    $provider = StorageProvider::factory()->for(Project::factory())->create();
    StorageProvider::factory()->for(Project::factory())->create();
    actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:read')->create());

    $this->getJson("/api/v1/projects/{$provider->project_id}/storage-providers")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data' => [['id', 'project_id', 'name', 'endpoint', 'region', 'bucket', 'path_prefix', 'path_style', 'access_key_id', 'verified_at', 'database_ids']]]);
});

test('testing a storage provider marks it verified', function () {
    fakeApiBucket();
    $provider = StorageProvider::factory()->create(['verified_at' => null]);
    actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:write')->create());

    $this->postJson("/api/v1/storage-providers/{$provider->id}/test")
        ->assertOk()
        ->assertJsonPath('data.id', $provider->id);

    expect($provider->refresh()->verified_at)->not->toBeNull();
});

test('a storage provider used for archives cannot be deleted', function () {
    $provider = StorageProvider::factory()->create(['name' => 'backups']);
    $database = Database::factory()->for($provider->project)->create(['name' => 'orders']);
    PointInTimeRecovery::factory()->for($database)->for($provider)->create();
    actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:write')->create());

    $this->deleteJson("/api/v1/storage-providers/{$provider->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['provider' => 'orders archives to backups, turn off its point-in-time recovery first.']);
});

test('an unused storage provider is deleted', function () {
    $provider = StorageProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:write')->create());

    $this->deleteJson("/api/v1/storage-providers/{$provider->id}")->assertNoContent();

    $this->assertModelMissing($provider);
});

test('a storage provider in another project is not found for a limited key', function () {
    $provider = StorageProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:read')->limitedTo(Project::factory()->create())->create());

    $this->getJson("/api/v1/storage-providers/{$provider->id}")->assertNotFound();
});

test('changing storage providers needs storage-providers:write', function () {
    $provider = StorageProvider::factory()->create();
    actingAsApiKey(ApiKey::factory()->withScopes('storage-providers:read')->create());

    $this->postJson("/api/v1/storage-providers/{$provider->id}/test")->assertForbidden();
});
