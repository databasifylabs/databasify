<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\DeployNode;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;

final readonly class RejoinMembers
{
    public function __construct(
        private DeployNode $deploy,
        private RollingNodes $rolling,
        private RefreshDatabaseStatus $refresh,
    ) {}

    /**
     * @param  list<string>  $nodeIds
     */
    public function handle(Database $database, array $nodeIds): void
    {
        DatabaseNode::query()->with('server', 'database')->findMany($nodeIds)->sortBy('position')->each(function (DatabaseNode $node): void {
            $this->deploy->handle($node->database, $node);
            $this->rolling->backOnline($node, expectPrimary: false);
        });

        $this->refresh->handle($database);
        $database->markReady();
    }
}
