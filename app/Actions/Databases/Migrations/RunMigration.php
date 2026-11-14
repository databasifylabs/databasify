<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Engines\Data\MigrationSource as EngineSource;
use App\Engines\Data\MigrationStart;
use App\Models\DatabaseMigration;
use Throwable;

final readonly class RunMigration
{
    public function __construct(
        private FailMigration $fail,
        private CleanUpEndedMigration $cleanUpEnded,
    ) {}

    public function handle(DatabaseMigration $migration): void
    {
        try {
            $migration->host()->driver()->migration()->start($this->startOf($migration));
        } catch (Throwable $exception) {
            if (! $this->fail->handle($migration, $exception->getMessage())) {
                $this->cleanUpEnded->handle($migration);
            }

            throw $exception;
        }

        $migration->markCopying();
        $this->cleanUpEnded->handle($migration);
    }

    private function startOf(DatabaseMigration $migration): MigrationStart
    {
        $source = $migration->source;
        $owner = $migration->database->settings->defaultUsername();

        return new MigrationStart(EngineSource::of($source), $source->databases, ! $migration->isOneTime(), $owner === '' ? null : $owner);
    }
}
