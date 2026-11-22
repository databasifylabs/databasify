<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\OAuthClient;

final readonly class OAuthFlowResult
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public OAuthClient $client,
    ) {}
}
