<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Models\DatabaseMigration;
use App\Support\Messages\Sentence;
use Throwable;

final readonly class CleanUpMigration
{
    public function handle(DatabaseMigration $migration): void
    {
        try {
            $migration->host()->driver()->migration()->stop();
        } catch (Throwable $exception) {
            $this->logFailureOf($migration, $exception);

            throw $exception;
        }

        $migration->markCleanedUp();
    }

    private function logFailureOf(DatabaseMigration $migration, Throwable $exception): void
    {
        $server = $migration->host()->server;

        $server->logFailure('Clean up migration', "Could not clean up the migration on {$server->name}: ".Sentence::of($exception->getMessage()).' The migration has ended, but its copy or replication from the source may still be running. Use Clean up again on the Migrations tab.', $migration->database);
    }
}
