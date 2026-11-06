<?php

declare(strict_types=1);

namespace App\Support\Concurrency;

final readonly class Packed
{
    public static function of(mixed $value): string
    {
        return base64_encode(serialize($value));
    }

    public static function open(mixed $packed): mixed
    {
        if (! is_string($packed)) {
            return null;
        }

        $decoded = base64_decode($packed, true);

        return $decoded === false ? null : unserialize($decoded);
    }
}
