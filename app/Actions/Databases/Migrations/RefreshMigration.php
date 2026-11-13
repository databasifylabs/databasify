<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\DTOs\Migrations\MigrationProgress;
use App\Enums\Migrations\MigrationPhase;
use App\Enums\Migrations\MigrationStatus;
use App\Models\DatabaseMigration;
use Throwable;

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

        $progress = $this->progressOf($migration);

        match ($progress->phase) {
            MigrationPhase::Broken => $this->fail->handle($migration, $progress->detail),
            MigrationPhase::Stalled => $this->stall($migration, $progress),
            MigrationPhase::Working => $migration->recordProgress($progress->detail),
            MigrationPhase::CaughtUp => $this->advance($migration, $progress),
        };
    }

    private function progressOf(DatabaseMigration $migration): MigrationProgress
    {
        try {
            $host = $migration->host();

            if (! $host->isPrimary()) {
                return $migration->hostLostPrimary()
                    ? MigrationProgress::broken("The primary moved away from {$host->server->name} during the migration, start it again.")
                    : MigrationProgress::stalled("{$host->server->name} did not report as the primary, checking again.");
            }

            return $host->driver()->migration()->progress();
        } catch (Throwable $exception) {
            return MigrationProgress::stalled("Could not check the migration: {$exception->getMessage()}");
        }
    }

    private function stall(DatabaseMigration $migration, MigrationProgress $progress): void
    {
        if ($migration->stallsReach(self::TOLERATED_STALLS)) {
            $this->fail->handle($migration, $progress->detail);

            return;
        }

        $migration->recordStall($progress->detail);
    }

    private function advance(DatabaseMigration $migration, MigrationProgress $progress): void
    {
        if ($migration->isSyncing()) {
            $migration->recordSyncProgress($progress->detail, $progress->lagSeconds);

            return;
        }

        if ($migration->isOneTime()) {
            $this->complete($migration, $progress);

            return;
        }

        if (! $migration->claimSyncing()) {
            return;
        }

        $this->cleanUpEnded->handle($migration);
    }

    private function complete(DatabaseMigration $migration, MigrationProgress $progress): void
    {
        if (! $migration->finishIfActive(MigrationStatus::Completed, $progress->detail)) {
            return;
        }

        $this->recordSchemas->handle($migration);
        $this->cleanUpEnded->handle($migration);
    }
}
