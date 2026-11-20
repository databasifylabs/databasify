<?php

declare(strict_types=1);

use App\Actions\Api\CreateApiKey;
use App\Actions\Api\RevokeApiKey;
use App\DTOs\Api\CreatedApiKey;
use App\DTOs\Api\NewApiKey;
use App\Enums\Api\ApiKeyExpiry;
use App\Models\User;
use Laravel\Passport\Passport;

function systemApiKey(ApiKeyExpiry $expiry = ApiKeyExpiry::Days30): CreatedApiKey
{
    return app(CreateApiKey::class)->handle(User::factory()->create(), new NewApiKey('ci', $expiry, ['system:read'], null));
}

test('the api needs a key', function () {
    $this->get('/api/v1/system')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('a valid key reaches the api', function () {
    $this->withToken(systemApiKey()->plainTextKey)
        ->getJson('/api/v1/system')
        ->assertOk();
});

test('a revoked key is refused', function () {
    $created = systemApiKey();
    app(RevokeApiKey::class)->handle($created->key);

    $this->withToken($created->plainTextKey)
        ->getJson('/api/v1/system')
        ->assertUnauthorized();
});

test('a key without the system scope gets 403', function () {
    Passport::actingAs(User::factory()->create(), ['databases:read'], 'api');

    $this->getJson('/api/v1/system')->assertForbidden();
});

test('system shows the version before any release check', function () {
    Passport::actingAs(User::factory()->create(), ['system:read'], 'api');

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
    $key = systemApiKey()->plainTextKey;

    foreach (range(1, 120) as $request) {
        $this->withToken($key)->getJson('/api/v1/system')->assertOk();
    }

    $this->withToken($key)->getJson('/api/v1/system')->assertTooManyRequests();

    app('auth')->forgetGuards();
    $this->withToken(systemApiKey()->plainTextKey)->getJson('/api/v1/system')->assertOk();
});

test('last use is recorded at most once a minute', function () {
    $created = systemApiKey();

    $this->withToken($created->plainTextKey)->getJson('/api/v1/system')->assertOk();
    $first = $created->key->refresh()->last_used_at;

    $this->travel(10)->seconds();
    $this->withToken($created->plainTextKey)->getJson('/api/v1/system')->assertOk();

    expect($first)->not->toBeNull()
        ->and($created->key->refresh()->last_used_at?->equalTo($first))->toBeTrue();

    $this->travel(61)->seconds();
    $this->withToken($created->plainTextKey)->getJson('/api/v1/system')->assertOk();

    expect($created->key->refresh()->last_used_at?->greaterThan($first))->toBeTrue();
});
