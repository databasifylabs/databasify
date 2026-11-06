<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Models\DatabaseMigration;
use Throwable;

final readonly class RunMigration
{
    public function __construct(private FailMigration $fail, private CleanUpEndedMigration $cleanUpEnded) {}

    public function handle(DatabaseMigration $migration): void
    {
        try {
            $migration->database->engineMigration()->start($migration);
        } catch (Throwable $exception) {
            if (! $this->fail->handle($migration, $exception->getMessage())) {
                $this->cleanUpEnded->handle($migration);
            }

            throw $exception;
        }

        $migration->markCopying();
        $this->cleanUpEnded->handle($migration);
    }
}
