<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Enums\Migrations\MigrationStatus;
use App\Jobs\Databases\StopMigrationJob;
use App\Models\DatabaseMigration;
use Illuminate\Validation\ValidationException;

final readonly class StopMigration
{
    public function handle(DatabaseMigration $migration, bool $cutover, bool $force = false): void
    {
        if (! $migration->isActive()) {
            $this->ensureCleanableAgain($migration, $force);
        }

        if ($cutover && $migration->status !== MigrationStatus::Syncing) {
            throw ValidationException::withMessages(['migration' => 'The initial copy is still running, wait until the migration is syncing.']);
        }

        $migration->database->startOperation($cutover ? 'Finish migration' : 'Stop migration');
        dispatch(new StopMigrationJob($migration, $cutover, $force));
    }

    private function ensureCleanableAgain(DatabaseMigration $migration, bool $force): void
    {
        if (! $force || ! $migration->cleanupFailed()) {
            throw ValidationException::withMessages(['migration' => 'This migration has already ended.']);
        }

        if ($migration->database->isMigrating()) {
            throw ValidationException::withMessages(['migration' => "Another migration is running on {$migration->database->name}, stop it first."]);
        }
    }
}
