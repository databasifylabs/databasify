<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\RollingSteps;
use App\Engines\Flows\UpgradeFlow;
use App\Models\Database;

final readonly class RedeployDatabase
{
    public function __construct(
        private StartFlow $startFlow,
        private UpgradeFlow $flow,
    ) {}

    public function handle(Database $database, ?string $title = null): void
    {
        $this->startFlow->handle($database, $this->flow, RollingSteps::contextFor($database));

        if ($title !== null) {
            $database->retitleOperation($title);
        }
    }
}
