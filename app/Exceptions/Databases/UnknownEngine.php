<?php

declare(strict_types=1);

namespace App\Exceptions\Databases;

use RuntimeException;

final class UnknownEngine extends RuntimeException
{
    public static function named(string $id): self
    {
        return new self("No engine is registered as \"{$id}\". Install or enable the plugin that adds it.");
    }
}
