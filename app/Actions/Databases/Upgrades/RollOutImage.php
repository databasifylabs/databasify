<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\LearnEngine;
use App\Actions\Databases\Nodes\LearnLogUnits;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;

final readonly class RollOutImage
{
    public function __construct(
        private RollingNodes $rolling,
        private DeployNode $deploy,
        private BuildNodeConfig $nodeConfig,
        private LearnEngine $learn,
        private LearnLogUnits $logUnits,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database): void
    {

        $this->rolling->each($database, function (DatabaseNode $node, bool $expectPrimary) use ($database): void {
            $this->deploy->handle($database, $node);
            $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node));
            $this->rolling->backOnline($node, $expectPrimary);
        });

        $this->refresh->handle($database);
        $this->learn->handle($database, $database->primaryOrSeed());
        $this->logUnits->handle($database, $database->primaryOrSeed());
        $database->markReady();
    }
}
