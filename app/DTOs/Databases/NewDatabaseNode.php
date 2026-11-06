<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Enums\Databases\NodeKind;
use App\Models\Server;

final readonly class NewDatabaseNode
{
    public function __construct(
        public Server $server,
        public NodeKind $kind = NodeKind::Member,
        public ?string $name = null,
    ) {}
}
