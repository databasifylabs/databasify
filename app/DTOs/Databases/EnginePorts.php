<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class EnginePorts
{
    /**
     * @param  list<int>  $peers
     */
    public function __construct(
        public int $client,
        public ?int $pool,
        public array $peers,
    ) {}
}
