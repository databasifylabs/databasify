<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Enums\Migrations\MigrationPhase;
use App\Enums\Migrations\MigrationStatus;
use App\Models\DatabaseMigration;

final readonly class RefreshMigration
{
    private const int TOLERATED_STALLS = 5;

    public function __construct(
        private FailMigration $fail,
        private RecordMigratedSchemas $recordSchemas,
        private CleanUpEndedMigration $cleanUpEnded,
    ) {}

    public function handle(DatabaseMigration $migration): void
    {
        if (! $migration->isActive()) {
            return;
        }

        $progress = $migration->currentProgress();

        if ($progress->phase === MigrationPhase::Broken || ($progress->phase === MigrationPhase::Stalled && $migration->stallsReach(self::TOLERATED_STALLS))) {
            $this->fail->handle($migration, $progress->detail);

            return;
        }

        if ($progress->phase === MigrationPhase::Stalled) {
            $migration->recordStall($progress->detail);

            return;
        }

        if ($progress->phase === MigrationPhase::Working) {
            $migration->recordProgress($progress->detail);

            return;
        }

        if ($migration->isSyncing()) {
            $migration->recordSyncProgress($progress->detail, $progress->lagSeconds);

            return;
        }

        if ($migration->isOneTime() && $migration->finishIfActive(MigrationStatus::Completed, $progress->detail)) {
            $this->recordSchemas->handle($migration);
            $this->cleanUpEnded->handle($migration);

            return;
        }

        if (! $migration->isOneTime() && $migration->claimSyncing()) {
            $this->cleanUpEnded->handle($migration);
        }
    }
}
