<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Engines\Data\Operation;
use App\Exceptions\Engines\EngineTaskRunning;
use App\Models\DatabaseMigration;
use App\Services\Databases\EngineTasks;
use App\Support\Messages\Sentence;
use Throwable;

final readonly class CleanUpMigration
{
    public const string STEP = 'migration-stop';

    public function __construct(private EngineTasks $tasks) {}

    public function handle(DatabaseMigration $migration, string $run): void
    {
        $host = $migration->host();

        try {
            $this->tasks->await($host, $run, self::STEP, 'Clean up migration', fn (string $key): Operation => $host->driver()->migration()->stop($key));
        } catch (EngineTaskRunning $running) {
            throw $running;
        } catch (Throwable $exception) {
            $server = $host->server;
            $server->logFailure('Clean up migration', "Could not clean up the migration on {$server->name}: ".Sentence::of($exception->getMessage()).' The migration has ended, but its copy or replication from the source may still be running. Use Clean up again on the Migrations tab.', $migration->database);

            throw $exception;
        }

        $migration->markCleanedUp();
    }
}
