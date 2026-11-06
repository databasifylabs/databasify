<?php

declare(strict_types=1);

namespace App\DTOs\Dns;

final readonly class DnsZoneData
{
    public function __construct(
        public string $externalId,
        public string $name,
    ) {}
}
