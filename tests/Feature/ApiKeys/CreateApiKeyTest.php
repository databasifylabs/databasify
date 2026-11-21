<?php

declare(strict_types=1);

use App\Actions\Api\CreateApiKey;
use App\Actions\Api\RevokeApiKey;
use App\DTOs\Api\NewApiKey;
use App\Enums\Api\ApiArea;
use App\Enums\Api\ApiKeyExpiry;
use App\Models\Project;
use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

test('creating a key stores its scopes, expiry and projects', function () {
    $project = Project::factory()->create();

    $created = app(CreateApiKey::class)->handle(
        User::factory()->create(),
        new NewApiKey('staging-ci', ApiKeyExpiry::Days90, ['databases:write', 'servers:read'], [$project->id]),
    );

    expect($created->plainTextKey)->not->toBeEmpty()
        ->and($created->key->name)->toBe('staging-ci')
        ->and($created->key->scopes)->toBe(['databases:write', 'servers:read'])
        ->and($created->key->expires_at?->between(now()->addDays(89), now()->addDays(91)))->toBeTrue()
        ->and($created->key->all_projects)->toBeFalse()
        ->and($created->key->allowedProjectIds())->toBe([$project->id]);
});

test('a key for all projects is not limited', function () {
    $created = app(CreateApiKey::class)->handle(User::factory()->create(), new NewApiKey('grafana', ApiKeyExpiry::Days30, ['servers:read'], null));

    expect($created->key->allowedProjectIds())->toBeNull();
});

test('a key that never expires lasts 100 years', function () {
    $created = app(CreateApiKey::class)->handle(User::factory()->create(), new NewApiKey('backup-cron', ApiKeyExpiry::Never, ['databases:read'], null));

    expect($created->key->expires_at?->year)->toBeGreaterThanOrEqual(now()->addYears(99)->year);
});

test('the personal access client is created once', function () {
    $owner = User::factory()->create();

    app(CreateApiKey::class)->handle($owner, new NewApiKey('one', ApiKeyExpiry::Days30, ['servers:read'], null));
    app(CreateApiKey::class)->handle($owner, new NewApiKey('two', ApiKeyExpiry::Days30, ['servers:read'], null));

    expect(Client::query()->count())->toBe(1);
});

test('revoking a key marks it revoked', function () {
    $created = app(CreateApiKey::class)->handle(User::factory()->create(), new NewApiKey('ci', ApiKeyExpiry::Days30, ['servers:read'], null));

    app(RevokeApiKey::class)->handle($created->key);

    expect($created->key->refresh()->revoked)->toBeTrue();
});

test('the api areas register 13 scopes without system:write', function () {
    expect(ApiArea::scopes())->toHaveCount(13)->not->toHaveKey('system:write')
        ->and(Passport::scopeIds())->toContain(...array_keys(ApiArea::scopes()));
});
