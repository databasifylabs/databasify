<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Actions\Databases\RunOnPrimary;
use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Data\MigrationSource as EngineSource;
use App\Engines\Requests\MigrationCheck;
use App\Models\Database;

final readonly class CheckMigrationSource
{
    public function __construct(private RunOnPrimary $primary) {}

    public function handle(Database $database, MigrationSource $source): SourceReport
    {
        return SourceReport::fromEngine($this->primary->handle($database, new MigrationCheck(EngineSource::of($source), $source->databases), 'Check the migration source'));
    }
}
