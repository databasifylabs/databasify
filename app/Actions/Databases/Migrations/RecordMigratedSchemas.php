<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\DTOs\Migrations\SourceReport;
use App\Models\DatabaseMigration;

final readonly class RecordMigratedSchemas
{
    public function __construct(
        private CheckMigrationSource $check,
        private RecordSchemas $record,
    ) {}

    public function handle(DatabaseMigration $migration): void
    {
        $database = $migration->database;

        if (! $database->managesAccounts()) {
            return;
        }

        $source = $migration->source;
        $report = $source->databases === [] ? rescue(fn (): SourceReport => $this->check->handle($database, $source), report: false) : null;
        $names = match (true) {
            $source->databases !== [] => $source->databases,
            $report?->reachable === true => $report->databases,
            default => $source->discovered,
        };

        $copied = $this->record->handle($database, $names);
        $default = $database->settings->defaultUsername();

        if ($copied === [] || $default === '') {
            return;
        }

        $database->driver()->accounts()->grant($default, $database->writeGrants(), false);
    }
}
