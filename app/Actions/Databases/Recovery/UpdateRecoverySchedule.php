<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\RecoverySchedule;
use App\Models\Database;

final readonly class UpdateRecoverySchedule
{
    public function __construct(private QueueRecoveryFiles $files) {}

    public function handle(Database $database, RecoverySchedule $schedule): void
    {
        $database->ensureIdle();
        $database->recoveryOrFail()->reschedule($schedule);

        $this->files->handle($database, 'Update point-in-time recovery');
    }
}
