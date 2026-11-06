<?php

declare(strict_types=1);

namespace App\DTOs\Acme;

final readonly class AcmeCertificate
{
    public function __construct(
        public string $chain,
        public string $key,
    ) {}
}
