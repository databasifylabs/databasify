<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class StartDatabaseNode
{
    public function handle(Database $database, DatabaseNode $node, bool $expectPrimary, bool $joining = false, bool $bootstrapsCluster = false): void
    {
        $engine = $database->provider();

        if ($joining) {
            $engine->cluster()->admit($node);
        }

        $engine->nodes()->start($node, $bootstrapsCluster);

        if ($joining) {
            $engine->cluster()->adopt($node);
        }

        $engine->nodes()->waitUntilReady($node, $expectPrimary, "{$node->server->name}: waiting until ready");
    }
}
