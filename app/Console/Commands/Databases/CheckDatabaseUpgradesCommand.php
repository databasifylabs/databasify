<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Jobs\Databases\CheckDatabaseUpgradeJob;
use App\Models\Database;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:check-upgrades')]
#[Description('Queue a check for a newer image release of every database')]
final class CheckDatabaseUpgradesCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Database::query()
            ->lazyById()
            ->each(function (Database $database) use (&$queued): void {
                dispatch(new CheckDatabaseUpgradeJob($database));
                $queued++;
            });

        $this->components->info("Queued {$queued} upgrade checks.");

        return self::SUCCESS;
    }
}
