<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Upgrades\RollOutNodes;
use App\Actions\Servers\ReadServerFacts;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class RetuneNodes
{
    public function __construct(
        private ReadServerFacts $facts,
        private RollOutNodes $rollOut,
        private NodeRunner $nodeRunner,
    ) {}

    public function handle(Database $database): void
    {
        $nodes = $database->nodesWithServers();
        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $this->facts->handle($node->server),
            "reading server facts on {$nodes->count()} nodes",
        );
        $database->markRetuned();
        $this->rollOut->handle($database, installUpdate: false);
    }
}
