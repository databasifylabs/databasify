<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\RetuneFlow;
use App\Engines\Flows\RollingSteps;
use App\Models\Database;

final readonly class RetuneDatabase
{
    public function __construct(
        private StartFlow $startFlow,
        private RetuneFlow $flow,
    ) {}

    public function handle(Database $database): void
    {
        $database->ensureIdle();

        $this->startFlow->handle($database, $this->flow, RollingSteps::contextFor($database));
    }
}
