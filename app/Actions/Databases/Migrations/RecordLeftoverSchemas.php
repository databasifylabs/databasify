<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\DatabaseProviders\Groups\EngineAccounts;
use App\Models\DatabaseMigration;

final readonly class RecordLeftoverSchemas
{
    public function __construct(private RecordSchemas $record) {}

    public function handle(DatabaseMigration $migration): void
    {
        $database = $migration->database;
        $accounts = $database->provider()->accounts();
        $copied = $migration->source->copiedDatabases();

        if ($accounts === null || $copied === []) {
            return;
        }

        $this->record->handle($database, $accounts, array_values(array_intersect($copied, $this->targetDatabasesOf($accounts))));
    }

    /**
     * @return list<string>
     */
    private function targetDatabasesOf(EngineAccounts $accounts): array
    {
        return rescue($accounts->listDatabases(...), [], report: false);
    }
}
