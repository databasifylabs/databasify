<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class UpdateDatabaseMembership
{
    public function __construct(private NodeRunner $nodeRunner) {}

    public function handle(Database $database, ?DatabaseNode $except = null): void
    {
        $nodes = $database->nodesWithServers()->reject(fn (DatabaseNode $node): bool => $node->is($except))->values();

        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $database->provider()->cluster()->updateMembership($node),
            "updating membership on {$nodes->count()} nodes",
        );
    }
}
