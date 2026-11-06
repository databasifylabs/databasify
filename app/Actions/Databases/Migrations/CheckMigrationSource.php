<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceReport;
use App\Models\Database;

final readonly class CheckMigrationSource
{
    public function handle(Database $database, MigrationSource $source): SourceReport
    {
        return $database->engineMigration()->check($source);
    }
}
