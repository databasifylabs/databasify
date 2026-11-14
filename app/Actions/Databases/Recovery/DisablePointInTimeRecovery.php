<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\DTOs\Recovery\StoredArchive;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\ApplySettingsJob;
use App\Jobs\Databases\DeleteRecoveryBackupsJob;
use App\Models\Database;

final readonly class DisablePointInTimeRecovery
{
    private const int BACKUP_DELETE_DELAY_MINUTES = 10;

    public function handle(Database $database, bool $deleteBackups = false): void
    {
        $database->ensureIdle();
        $archive = $database->markBusyWith(DatabaseStatus::Updating, fn (Database $locked): StoredArchive => $locked->disableRecovery());
        dispatch(new ApplySettingsJob($database->refresh(), archiveChanged: true));

        if ($deleteBackups) {
            dispatch(new DeleteRecoveryBackupsJob($database, $archive))->delay(now()->addMinutes(self::BACKUP_DELETE_DELAY_MINUTES));
        }
    }
}
