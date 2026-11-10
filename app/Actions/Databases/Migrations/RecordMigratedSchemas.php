<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Actions\Databases\RunOnPrimary;
use App\DTOs\Migrations\SourceReport;
use App\Engines\Requests\UserGrant;
use App\Models\DatabaseMigration;

final readonly class RecordMigratedSchemas
{
    public function __construct(
        private CheckMigrationSource $check,
        private RecordSchemas $record,
        private RunOnPrimary $primary,
    ) {}

    public function handle(DatabaseMigration $migration): void
    {
        $database = $migration->database;

        if (! $database->managesAccounts()) {
            return;
        }

        $copied = $this->record->handle($database, $this->sourceDatabasesOf($migration));
        $default = $database->settings->defaultUsername();

        if ($copied === [] || $default === '') {
            return;
        }

        $this->primary->handle($database, new UserGrant($default, $database->writeGrants()), "Grant {$default} the migrated databases");
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
