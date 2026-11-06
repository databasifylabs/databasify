<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class NewTrustedSource
{
    public function __construct(
        public string $cidr,
        public ?string $label = null,
    ) {}

    public static function normalized(string $source, ?string $label = null): self
    {
        return new self(str_contains($source, '/') ? $source : "{$source}/32", $label);
    }
}
