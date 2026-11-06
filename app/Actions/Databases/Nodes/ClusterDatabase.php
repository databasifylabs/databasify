<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class ClusterDatabase
{
    public function __construct(private ConfigureDatabaseNode $configure) {}

    public function handle(Database $database, DatabaseNode $newNode): void
    {
        $database->nodesOtherThan($newNode)->each(fn (DatabaseNode $node) => $this->clusterOn($database, $node));
    }

    private function clusterOn(Database $database, DatabaseNode $node): void
    {
        $this->configure->handle($database, $node);
        $database->provider()->cluster()->cluster($node);
    }
}
