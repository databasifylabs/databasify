<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Support\Tls\CertificateFingerprint;

final readonly class NodeCertificate
{
    public function __construct(
        public string $certificate,
        public string $key,
        public string $ca,
    ) {}

    public function fingerprint(): ?string
    {
        return CertificateFingerprint::of($this->certificate);
    }
}
