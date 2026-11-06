<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Jobs\Databases\RecordDatabaseMetricsJob;
use App\Models\Database;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:record-metrics')]
#[Description('Queue a metrics sample for every running database')]
final class RecordDatabaseMetricsCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Database::query()->operational()
            ->lazyById()
            ->each(function (Database $database) use (&$queued): void {
                dispatch(new RecordDatabaseMetricsJob($database));
                $queued++;
            });

        $this->components->info("Queued {$queued} metric samples.");

        return self::SUCCESS;
    }
}
