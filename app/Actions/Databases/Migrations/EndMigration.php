<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Enums\Migrations\MigrationStatus;
use App\Models\DatabaseMigration;
use Throwable;

final readonly class EndMigration
{
    public function __construct(
        private RecordMigratedSchemas $recordSchemas,
        private RecordLeftoverSchemas $recordLeftovers,
        private CleanUpMigration $cleanUp,
    ) {}

    public function handle(DatabaseMigration $migration, bool $cutover, bool $force = false): void
    {
        if (! $this->claimEndOf($migration, $cutover) && ! $force) {
            return;
        }

        if ($migration->status === MigrationStatus::Completed) {
            $this->recordSchemas->handle($migration);
            $this->cleanUp($migration, $force);

            return;
        }

        $this->cleanUp($migration, $force);
        $this->recordLeftovers->handle($migration);
    }

    private function cleanUp(DatabaseMigration $migration, bool $force): void
    {
        try {
            $this->cleanUp->handle($migration);
        } catch (Throwable $exception) {
            throw_unless($force, $exception);
        }
    }

    private function claimEndOf(DatabaseMigration $migration, bool $cutover): bool
    {
        return $cutover
            ? $migration->finishIfActive(MigrationStatus::Completed, 'Finished: the database no longer follows the source, point your app at it.')
            : $migration->finishIfActive(MigrationStatus::Stopped, 'Stopped before finishing.');
    }
}
