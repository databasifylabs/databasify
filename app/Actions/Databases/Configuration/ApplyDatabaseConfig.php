<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Nodes\ConfigureDatabaseNode;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class ApplyDatabaseConfig
{
    public function __construct(
        private ConfigureDatabaseNode $configure,
        private NodeRunner $nodeRunner,
    ) {}

    /**
     * @param  list<string>  $keys
     */
    public function handle(Database $database, array $keys): void
    {
        $nodes = $database->nodesWithServers();
        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $this->applyConfigOn($database, $node, $keys),
            "applying settings on {$nodes->count()} nodes",
        );
    }

    /**
     * @param  list<string>  $keys
     */
    private function applyConfigOn(Database $database, DatabaseNode $node, array $keys): void
    {
        $this->configure->handle($database, $node);
        $database->provider()->nodes()->applyConfig($node, $keys);
    }
}
