<?php

declare(strict_types=1);

namespace App\Exceptions\Plugins;

use RuntimeException;

final class UndeclaredEngine extends RuntimeException
{
    public static function named(string $id): self
    {
        return new self("Declare the \"{$id}\" engine in extra.databasify.engines before registering it.");
    }
}
