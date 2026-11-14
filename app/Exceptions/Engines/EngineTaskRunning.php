<?php

declare(strict_types=1);

namespace App\Exceptions\Engines;

use RuntimeException;

final class EngineTaskRunning extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("Engine task {$key} is still running.");
    }
}
