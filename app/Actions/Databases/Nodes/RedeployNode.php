<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;

final readonly class RedeployNode
{
    public function __construct(
        private RollingNodes $rolling,
        private DeployNode $deploy,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $this->rolling->each($database, function (DatabaseNode $node, bool $expectPrimary): void {
            $this->deploy->handle($node->database, $node);
            $this->rolling->backOnline($node, $expectPrimary);
        }, only: $node);

        $this->refresh->handle($database);
        $database->markReady();
    }
}
