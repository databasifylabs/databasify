<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\RecoverySchedule;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplySettingsJob;
use App\Models\Database;

final readonly class UpdateRecoverySchedule
{
    public function handle(Database $database, RecoverySchedule $schedule): void
    {
        $database->ensureIdle();
        $database->markBusyWith(DatabaseStatus::Updating, fn (Database $locked) => $locked->recoveryOrFail()->reschedule($schedule));
        dispatch(new ApplySettingsJob($database->refresh(), archiveChanged: true));
    }
}
