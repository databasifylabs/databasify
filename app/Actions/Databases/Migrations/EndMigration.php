<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Enums\Migrations\MigrationStatus;
use App\Exceptions\Engines\EngineTaskRunning;
use App\Models\DatabaseMigration;
use App\Services\Databases\EngineTasks;
use Throwable;

final readonly class EndMigration
{
    public function __construct(
        private RecordMigratedSchemas $recordSchemas,
        private RecordLeftoverSchemas $recordLeftovers,
        private CleanUpMigration $cleanUp,
        private EngineTasks $tasks,
    ) {}

    public function handle(DatabaseMigration $migration, bool $cutover, string $run, bool $force = false): void
    {
        $finished = $cutover
            ? $migration->finishIfActive(MigrationStatus::Completed, 'Finished: the database no longer follows the source, point your app at it.')
            : $migration->finishIfActive(MigrationStatus::Stopped, 'Stopped before finishing.');
        $claimed = $finished || $this->tasks->started($migration->host(), $run, CleanUpMigration::STEP);

        if (($claimed || $force) && $migration->status === MigrationStatus::Completed) {
            $this->recordSchemas->handle($migration);
            $this->cleanUp($migration, $run, $force);
        }

        if (($claimed || $force) && $migration->status !== MigrationStatus::Completed) {
            $this->cleanUp($migration, $run, $force);
            $this->recordLeftovers->handle($migration);
        }

        $migration->database->markReady();
    }

    private function cleanUp(DatabaseMigration $migration, string $run, bool $force): void
    {
        try {
            $this->cleanUp->handle($migration, $run);
        } catch (EngineTaskRunning $running) {
            throw $running;
        } catch (Throwable $exception) {
            throw_unless($force, $exception);
            $migration->logCleanupFailure($exception);
        }
    }
}
