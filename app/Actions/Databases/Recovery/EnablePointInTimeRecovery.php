<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\RecoverySettings;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplySettingsJob;
use App\Models\Database;

final readonly class EnablePointInTimeRecovery
{
    public function handle(Database $database, RecoverySettings $settings): void
    {
        $database->ensureIdle();
        $database->markBusyWith(DatabaseStatus::Updating, fn (Database $locked) => $locked->enableRecovery($settings));
        dispatch(new ApplySettingsJob($database->refresh(), archiveChanged: true));
    }
}
