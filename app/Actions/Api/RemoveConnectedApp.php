<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Models\OAuthClient;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

final readonly class RemoveConnectedApp
{
    public function handle(OAuthClient $client): void
    {
        DB::transaction(function () use ($client): void {
            $client->forceFill(['revoked' => true])->save();
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $client->tokens()->select('id'))->update(['revoked' => true]);
            $client->tokens()->update(['revoked' => true]);
        });
    }
}
