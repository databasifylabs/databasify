<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Enums\Migrations\MigrationStatus;
use App\Events\DatabaseAlertRaised;
use App\Models\DatabaseMigration;

final readonly class FailMigration
{
    public function __construct(private CleanUpEndedMigration $cleanUpEnded, private RecordLeftoverSchemas $recordLeftovers) {}

    public function handle(DatabaseMigration $migration, string $reason): bool
    {
        if (! $migration->finishIfActive(MigrationStatus::Failed, $reason)) {
            return false;
        }

        $this->cleanUpEnded->handle($migration);
        $this->recordLeftovers->handle($migration);
        event(new DatabaseAlertRaised($migration->database, "Migration into {$migration->database->name} failed", $reason));

        return true;
    }
}
