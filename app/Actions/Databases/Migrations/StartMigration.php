<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\DTOs\Migrations\MigrationSource;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Migrations\MigrationMode;
use App\Jobs\Databases\RunMigrationJob;
use App\Models\Database;
use App\Models\DatabaseMigration;
use Illuminate\Validation\ValidationException;

final readonly class StartMigration
{
    public function __construct(private CheckMigrationSource $check) {}

    public function handle(Database $database, MigrationSource $source, MigrationMode $mode): DatabaseMigration
    {
        $database->ensureIdle();
        $primary = $database->primary() ?? throw ValidationException::withMessages(['database' => "{$database->name} has no healthy primary to migrate into."]);
        $report = $this->check->handle($database, $source);

        if (! $report->reachable) {
            throw ValidationException::withMessages(['source' => "Could not connect to the source: {$report->error}"]);
        }

        if ($report->occupied !== []) {
            $occupied = $report->occupiedNames();

            throw ValidationException::withMessages(['source' => "These databases already hold rows here, from an earlier copy or your own data: {$occupied}. Delete them on the Users & Databases tab, or drop them on the server yourself, or pick other databases."]);
        }

        if ($report->problems !== []) {
            throw ValidationException::withMessages(['source' => $report->problems]);
        }

        if ($mode === MigrationMode::Continuous && ! $report->supportsContinuous()) {
            throw ValidationException::withMessages(['mode' => array_merge(['Continuous sync is not possible with this source:'], $report->blockers)]);
        }

        $source = $source->databases === [] ? $source->withDiscovered($report->databases) : $source;

        $migration = $database->markBusyWith(DatabaseStatus::Migrating, fn (Database $locked): DatabaseMigration => $locked->startMigration($primary, $source, $mode));
        dispatch(new RunMigrationJob($migration));

        return $migration;
    }
}
