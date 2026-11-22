<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Api\CopyClientAccess;
use App\Models\ApiKey;
use App\Models\OAuthClient;
use Laravel\Passport\Events\AccessTokenCreated;

final readonly class CopyClientAccessToToken
{
    public function __construct(private CopyClientAccess $copy) {}

    public function handle(AccessTokenCreated $event): void
    {
        $client = OAuthClient::query()->whereNotNull('approved_at')->find($event->clientId);

        if ($client === null) {
            return;
        }

        $this->copy->handle($client, ApiKey::query()->findOrFail($event->tokenId));
    }
}
