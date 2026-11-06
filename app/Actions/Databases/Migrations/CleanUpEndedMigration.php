<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Models\DatabaseMigration;

final readonly class CleanUpEndedMigration
{
    public function __construct(private CleanUpMigration $cleanUp) {}

    public function handle(DatabaseMigration $migration): void
    {
        if ($migration->refresh()->isActive()) {
            return;
        }

        rescue(fn () => $this->cleanUp->handle($migration), report: false);
    }
}
