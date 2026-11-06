<?php

declare(strict_types=1);

namespace App\DTOs\Providers;

use App\DTOs\Servers\PrivateNetworkChoice;
use App\Enums\Servers\OperatingSystem;

final readonly class ProviderServerOrder
{
    public function __construct(
        public string $serverProviderId,
        public string $name,
        public string $region,
        public string $plan,
        public OperatingSystem $os,
        public ?string $networkExternalId,
        public bool $newNetwork = false,
        public ?PrivateNetworkChoice $databasifyNetwork = null,
    ) {}
}
