<?php

declare(strict_types=1);

namespace App\DatabaseProviders\Drivers\Postgres;

use App\DatabaseProviders\Groups\EngineNodes;
use App\Models\DatabaseNode;

final readonly class PostgresNodes extends EngineNodes
{
    public function applyPools(DatabaseNode $node): void
    {
        $this->runStep($node, 'config.pools', $this->configFilesFor($node), "{$node->server->name}: updating connection pools");
    }

    public function syncConfig(DatabaseNode $primary): void
    {
        $this->runStep($primary, 'config.sync-config', $this->configFilesFor($primary), "{$primary->server->name}: syncing the configuration");
    }

    /**
     * @return array<string, mixed>
     */
    private function configFilesFor(DatabaseNode $node): array
    {
        return [...$this->scriptData()->for($node), 'files' => $this->engine->configFiles($node)];
    }
}
