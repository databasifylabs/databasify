<?php

declare(strict_types=1);

namespace App\Exceptions\Databases;

use LogicException;

final class MissingEngineScript extends LogicException
{
    public static function named(string $engine, string $name, string $view): self
    {
        return new self("The {$engine} engine has no {$name} script: {$view} does not exist.");
    }
}
