<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Actions\Databases\RunOnPrimary;
use App\Engines\Requests\AccountList;
use App\Models\DatabaseMigration;

final readonly class RecordLeftoverSchemas
{
    public function __construct(
        private RecordSchemas $record,
        private RunOnPrimary $primary,
    ) {}

    public function handle(DatabaseMigration $migration): void
    {
        $database = $migration->database;
        $copied = $migration->source->copiedDatabases();

        if (! $database->managesAccounts() || $copied === []) {
            return;
        }

        $present = rescue(fn (): array => $this->primary->handle($database, new AccountList)->databases, [], report: false);

        $this->record->handle($database, array_values(array_intersect($copied, $present)));
    }
}
