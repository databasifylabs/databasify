<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class TlsSettings
{
    /**
     * @param  list<string>  $files
     */
    public function __construct(
        public array $files,
        public bool $rsaKey,
        public bool $publicCertificate,
    ) {}
}
