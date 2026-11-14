<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\DeployNode;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;
use App\Services\Databases\RollingNodes;

final readonly class RejoinMembers
{
    public function __construct(
        private DeployNode $deploy,
        private RollingNodes $rolling,
        private EngineTasks $tasks,
        private RefreshDatabaseStatus $refresh,
    ) {}

    /**
     * @param  list<string>  $nodeIds
     */
    public function handle(Database $database, array $nodeIds, string $run): void
    {
        DatabaseNode::query()->with('server', 'database')->findMany($nodeIds)->sortBy('position')->each(function (DatabaseNode $node) use ($run): void {
            if (! $this->tasks->started($node, $run, 'ready')) {
                $this->deploy->handle($node->database, $node);
            }

            $this->rolling->backOnline($node, $run, expectPrimary: false);
        });

        $this->refresh->handle($database);
        $database->markReady();
    }
}
