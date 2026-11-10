<?php

declare(strict_types=1);

namespace App\Actions\Databases\Recovery;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\ArchiveFlow;
use App\Engines\Flows\RollingSteps;
use App\Jobs\Databases\DeleteRecoveryBackupsJob;
use App\Models\Database;

final readonly class DisablePointInTimeRecovery
{
    private const int BACKUP_DELETE_DELAY_MINUTES = 10;

    public function __construct(
        private StartFlow $startFlow,
        private ArchiveFlow $flow,
    ) {}

    public function handle(Database $database, bool $deleteBackups = false): void
    {
        $database->ensureIdle();
        $archive = $database->disableRecovery();

        $this->startFlow->handle($database->refresh(), $this->flow, RollingSteps::contextFor($database));

        if ($deleteBackups) {
            dispatch(new DeleteRecoveryBackupsJob($database, $archive))->delay(now()->addMinutes(self::BACKUP_DELETE_DELAY_MINUTES));
        }
    }
}
