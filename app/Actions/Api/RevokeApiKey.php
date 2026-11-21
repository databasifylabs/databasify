<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Models\ApiKey;

final readonly class RevokeApiKey
{
    public function handle(ApiKey $key): void
    {
        $key->revoke();
    }
}
