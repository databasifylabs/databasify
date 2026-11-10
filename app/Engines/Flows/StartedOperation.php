<?php

declare(strict_types=1);

namespace App\Engines\Flows;

final readonly class StartedOperation
{
    public function __construct(
        public string $nodeId,
        public string $key,
    ) {}
}
