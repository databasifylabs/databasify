<?php

declare(strict_types=1);

namespace App\DTOs\Images;

final readonly class ImageLabels
{
    private const string SDK = 'io.databasify.sdk';

    public function __construct(public ?string $sdk) {}

    public static function from(mixed $labels): self
    {
        $sdk = is_array($labels) ? $labels[self::SDK] ?? null : null;

        return new self(is_string($sdk) ? $sdk : null);
    }
}
