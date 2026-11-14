<?php

declare(strict_types=1);

namespace App\Actions\Databases\Migrations;

use App\Exceptions\Engines\EngineTaskRunning;
use App\Models\DatabaseMigration;
use Illuminate\Support\Str;
use Throwable;

final readonly class CleanUpEndedMigration
{
    public function __construct(private CleanUpMigration $cleanUp) {}

    public function handle(DatabaseMigration $migration): void
    {
        if ($migration->refresh()->isActive()) {
            return;
        }

        try {
            $this->cleanUp->handle($migration, strtolower(Str::ulid()->toBase32()));
        } catch (EngineTaskRunning $running) {
            $migration->host()->update(['task_key' => null]);
            $migration->logCleanupFailure($running);
        } catch (Throwable $exception) {
            $migration->logCleanupFailure($exception);
        }
    }
}
