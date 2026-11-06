<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\DTOs\Migrations\SourceReport;
use App\Models\DatabaseMigration;

final readonly class RecordMigratedSchemas
{
    public function __construct(private CheckMigrationSource $check, private RecordSchemas $record) {}

    public function handle(DatabaseMigration $migration): void
    {
        $database = $migration->database;
        $accounts = $database->provider()->accounts();

        if ($accounts === null) {
            return;
        }

        $copied = $this->record->handle($database, $accounts, $this->sourceDatabasesOf($migration));

        if ($copied === []) {
            return;
        }

        $accounts->grantDefaultUserOn($copied);
    }

    /**
     * @return list<string>
     */
    private function sourceDatabasesOf(DatabaseMigration $migration): array
    {
        $source = $migration->source;

        if ($source->databases !== []) {
            return $source->databases;
        }

        $report = rescue(fn (): SourceReport => $this->check->handle($migration->database, $source), report: false);

        if ($report?->reachable !== true) {
            return $source->discovered;
        }

        return $report->databases;
    }
}
