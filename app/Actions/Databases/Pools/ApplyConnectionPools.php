<?php

declare(strict_types=1);

namespace App\Actions\Databases\Pools;

use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class ApplyConnectionPools
{
    public function __construct(private NodeRunner $nodeRunner) {}

    public function handle(Database $database): void
    {
        $nodes = $database->nodesWithServers();
        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $database->provider()->nodes()->applyPools($node),
            "updating connection pools on {$nodes->count()} nodes",
        );
    }
}
