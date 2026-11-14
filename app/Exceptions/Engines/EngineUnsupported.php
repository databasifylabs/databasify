<?php

declare(strict_types=1);

namespace App\Exceptions\Engines;

final class EngineUnsupported extends EngineError
{
    public static function command(string $command): self
    {
        return new self('unsupported', "this engine does not implement \"{$command}\"");
    }
}
