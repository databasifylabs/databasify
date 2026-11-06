<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Jobs\Databases\ApplyMaintenanceJob;
use App\Models\Database;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:apply-maintenance')]
#[Description('Queue maintenance for every database whose weekly window is open now')]
final class ApplyMaintenanceCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Database::query()->inMaintenanceWindowAt(now())
            ->lazyById()
            ->each(function (Database $database) use (&$queued): void {
                dispatch(new ApplyMaintenanceJob($database));
                $queued++;
            });

        $this->components->info("Queued {$queued} maintenance runs.");

        return self::SUCCESS;
    }
}
