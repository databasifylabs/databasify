<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

final readonly class MongoDbRole
{
    public function __construct(
        public string $role,
        public string $db,
    ) {}
}
