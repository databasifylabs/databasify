<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Jobs\Databases\RefreshDatabaseStatusJob;
use App\Models\Database;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:refresh-status')]
#[Description('Queue a status check for every running database')]
final class RefreshDatabaseStatusCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Database::query()->operational()
            ->lazyById()
            ->each(function (Database $database) use (&$queued): void {
                dispatch(new RefreshDatabaseStatusJob($database));
                $queued++;
            });

        $this->components->info("Queued {$queued} status checks.");

        return self::SUCCESS;
    }
}
