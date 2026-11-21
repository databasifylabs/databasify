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
