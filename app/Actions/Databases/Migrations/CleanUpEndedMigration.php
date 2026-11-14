<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Models\DatabaseMigration;
use Illuminate\Support\Str;

final readonly class CleanUpEndedMigration
{
    public function __construct(private CleanUpMigration $cleanUp) {}

    public function handle(DatabaseMigration $migration): void
    {
        if ($migration->refresh()->isActive()) {
            return;
        }

        rescue(fn () => $this->cleanUp->handle($migration, strtolower(Str::ulid()->toBase32())), report: false);
    }
}
