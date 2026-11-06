<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use RuntimeException;

final class DuplicateProvider extends RuntimeException
{
    public static function named(string $id): self
    {
        return new self("A provider is already registered as \"{$id}\".");
    }
}
