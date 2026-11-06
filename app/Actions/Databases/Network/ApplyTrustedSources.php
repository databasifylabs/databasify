<?php

declare(strict_types=1);

namespace App\Actions\Databases\Network;

use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class ApplyTrustedSources
{
    public function __construct(
        private ApplyNodeFirewall $firewall,
        private NodeRunner $nodeRunner,
    ) {}

    public function handle(Database $database): void
    {
        $sources = $database->trustedCidrs();
        $nodes = $database->nodesWithServers();
        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $this->firewall->handle($database, $node, $sources),
            "applying trusted sources on {$nodes->count()} nodes",
        );
    }
}
