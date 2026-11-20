<?php

declare(strict_types=1);

use App\Actions\Api\CreateApiKey;
use App\Actions\Api\RevokeApiKey;
use App\DTOs\Api\NewApiKey;
use App\Enums\Api\ApiKeyExpiry;
use App\Models\ApiKey;
use App\Models\User;

test('the api needs a key', function () {
    $this->get('/api/v1/system')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('an issued key reaches the api', function () {
    $created = app(CreateApiKey::class)->handle(User::factory()->create(), new NewApiKey('ci', ApiKeyExpiry::Days30, ['system:read'], null));

    $this->withToken($created->plainTextKey)
        ->getJson('/api/v1/system')
        ->assertOk();
});

test('a revoked key is refused', function () {
    $created = app(CreateApiKey::class)->handle(User::factory()->create(), new NewApiKey('ci', ApiKeyExpiry::Days30, ['system:read'], null));
    app(RevokeApiKey::class)->handle($created->key);

    $this->withToken($created->plainTextKey)
        ->getJson('/api/v1/system')
        ->assertUnauthorized();
});

test('a key without the system scope gets 403', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('databases:read')->create());

    $this->getJson('/api/v1/system')->assertForbidden();
});

test('system shows the version before any release check', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('system:read')->create());

    $this->getJson('/api/v1/system')
        ->assertOk()
        ->assertExactJson(['data' => [
            'version' => config('app.version'),
            'latest_version' => null,
            'update_available' => false,
            'plugins' => [],
        ]]);
});

test('the api allows 120 requests a minute per key', function () {
    actingAsApiKey(ApiKey::factory()->withScopes('system:read')->create());

    foreach (range(1, 120) as $request) {
        $this->getJson('/api/v1/system')->assertOk();
    }

    $this->getJson('/api/v1/system')->assertTooManyRequests();

    actingAsApiKey(ApiKey::factory()->withScopes('system:read')->create());
    $this->getJson('/api/v1/system')->assertOk();
});

test('last use is recorded at most once a minute', function () {
    $key = ApiKey::factory()->withScopes('system:read')->create();
    actingAsApiKey($key);

    $this->getJson('/api/v1/system')->assertOk();
    $first = $key->refresh()->last_used_at;

    $this->travel(10)->seconds();
    $this->getJson('/api/v1/system')->assertOk();

    expect($first)->not->toBeNull()
        ->and($key->refresh()->last_used_at?->equalTo($first))->toBeTrue();

    $this->travel(61)->seconds();
    $this->getJson('/api/v1/system')->assertOk();

    expect($key->refresh()->last_used_at?->greaterThan($first))->toBeTrue();
});
