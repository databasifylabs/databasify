<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use InvalidArgumentException;

final readonly class Shell
{
    public function arg(string $value): string
    {
        return "'".str_replace("'", "'\\''", self::withoutNul($value))."'";
    }

    public static function withoutNul(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('Engine script values cannot contain a NUL byte.');
        }

        return $value;
    }
}
