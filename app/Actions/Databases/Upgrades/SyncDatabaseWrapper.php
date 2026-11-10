<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\RollingSteps;
use App\Engines\Flows\UpdateWrapperFlow;
use App\Models\Database;

final readonly class SyncDatabaseWrapper
{
    public function __construct(
        private StartFlow $startFlow,
        private UpdateWrapperFlow $flow,
    ) {}

    public function handle(Database $database): void
    {
        if ($database->runsPinnedWrapper() || ! $database->isReady() || $database->isBusy() || $database->isMigrating()) {
            return;
        }

        $this->startFlow->handle($database, $this->flow, RollingSteps::contextFor($database));
    }
}
