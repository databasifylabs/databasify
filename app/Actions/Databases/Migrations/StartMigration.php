<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\DTOs\Migrations\MigrationSource;
use App\DTOs\Migrations\SourceReport;
use App\Enums\Migrations\MigrationMode;
use App\Jobs\Databases\RunMigrationJob;
use App\Models\Database;
use App\Models\DatabaseMigration;
use App\Support\Migrations\NameList;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class StartMigration
{
    public function __construct(private CheckMigrationSource $check) {}

    public function handle(Database $database, MigrationSource $source, MigrationMode $mode): DatabaseMigration
    {
        $database->ensureIdle();
        $primary = $database->primary() ?? throw ValidationException::withMessages(['database' => "{$database->name} has no healthy primary to migrate into."]);
        $report = $this->check->handle($database, $source);
        $this->ensureReady($report, $mode);
        $source = $this->sourceDiscoveredBy($source, $report);

        $migration = DB::transaction(function () use ($database, $primary, $source, $mode): DatabaseMigration {
            $database->startOperation("Migrate from {$source->address()}");

            return $database->startMigration($primary, $source, $mode);
        });
        dispatch(new RunMigrationJob($migration));

        return $migration;
    }

    private function sourceDiscoveredBy(MigrationSource $source, SourceReport $report): MigrationSource
    {
        if ($source->databases !== []) {
            return $source;
        }

        return $source->withDiscovered($report->databases);
    }

    private function ensureReady(SourceReport $report, MigrationMode $mode): void
    {
        if (! $report->reachable) {
            throw ValidationException::withMessages(['source' => "Could not connect to the source: {$report->error}"]);
        }

        if ($report->occupied !== []) {
            $occupied = NameList::of($report->occupied);

            throw ValidationException::withMessages(['source' => "These databases already hold rows here, from an earlier copy or your own data: {$occupied}. Delete them on the Users & Databases tab, or drop them on the server yourself, or pick other databases."]);
        }

        if ($report->problems !== []) {
            throw ValidationException::withMessages(['source' => $report->problems]);
        }

        if ($mode === MigrationMode::Continuous && ! $report->supportsContinuous()) {
            throw ValidationException::withMessages(['mode' => array_merge(['Continuous sync is not possible with this source:'], $report->blockers)]);
        }
    }
}
