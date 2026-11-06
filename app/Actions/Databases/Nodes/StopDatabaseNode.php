<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class StopDatabaseNode
{
    public function handle(Database $database, DatabaseNode $node, bool $erase, string $log): void
    {
        $node->ssh()->exec('ssh.databases.nodes.stop', $database->provider()->scriptData()->stopping($erase), $log);
    }
}
