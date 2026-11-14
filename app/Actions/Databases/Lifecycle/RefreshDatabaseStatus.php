<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class RefreshDatabaseStatus
{
    public function __construct(private RefreshNodeStatuses $refreshNodes) {}

    public function handle(Database $database): DatabaseNodeCollection
    {
        return $this->refreshNodes->handle($database, $database->nodesWithServers()->reject(fn (DatabaseNode $node): bool => $node->isReadOnly() && $node->isBusy() && ! $node->isClaimedByDatabase())->values());
    }
}
