<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Actions\Databases\Flows\StartFlow;
use App\DTOs\Recovery\RecoverySettings;
use App\Engines\Flows\ArchiveFlow;
use App\Engines\Flows\RollingSteps;
use App\Models\Database;

final readonly class EnablePointInTimeRecovery
{
    public function __construct(
        private StartFlow $startFlow,
        private ArchiveFlow $flow,
    ) {}

    public function handle(Database $database, RecoverySettings $settings): void
    {
        $database->ensureIdle();
        $database->enableRecovery($settings);

        $this->startFlow->handle($database->refresh(), $this->flow, RollingSteps::contextFor($database));
        $database->retitleOperation('Turn on point-in-time recovery');
    }
}
