<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\DTOs\Databases\EngineImage;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class ConfigureDatabaseNode
{
    public function handle(Database $database, DatabaseNode $node, bool $fresh = false, ?EngineImage $image = null): void
    {
        $node->ssh()->exec(
            'ssh.databases.nodes.deploy',
            $database->provider()->scriptData()->deploy($node, $fresh, $image),
            "{$node->server->name}: writing configuration",
        );
    }
}
