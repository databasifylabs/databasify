<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Actions\Databases\Flows\StartFlow;
use App\DTOs\Recovery\RecoverySchedule;
use App\Engines\Flows\ArchiveFlow;
use App\Engines\Flows\FlowContext;
use App\Models\Database;

final readonly class UpdateRecoverySchedule
{
    public function __construct(
        private StartFlow $startFlow,
        private ArchiveFlow $flow,
    ) {}

    public function handle(Database $database, RecoverySchedule $schedule): void
    {
        $database->ensureIdle();
        $database->recoveryOrFail()->reschedule($schedule);

        $this->startFlow->handle($database->refresh(), $this->flow, new FlowContext([]));
    }
}
