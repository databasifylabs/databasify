<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Engines\Data\Operation;
use App\Enums\Migrations\MigrationStatus;
use App\Models\DatabaseMigration;
use App\Services\Databases\EngineTasks;

final readonly class CleanUpMigration
{
    public const string STEP = 'migration-stop';

    public function __construct(private EngineTasks $tasks) {}

    public function handle(DatabaseMigration $migration, string $run): void
    {
        $host = $migration->host();
        $cutover = $migration->status === MigrationStatus::Completed;

        $this->tasks->await($host, $run, self::STEP, 'Clean up migration', fn (string $key): Operation => $host->driver()->migration()->stop($key, $cutover));
        $migration->markCleanedUp();
    }
}
