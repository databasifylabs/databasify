<?php

declare(strict_types=1);

namespace App\DTOs\Providers;

final readonly class NewProviderServer
{
    public function __construct(
        public string $name,
        public string $region,
        public string $plan,
        public int|string $image,
        public ?string $networkExternalId,
        public string $sshKeyId,
        public string $publicKey,
    ) {}
}
