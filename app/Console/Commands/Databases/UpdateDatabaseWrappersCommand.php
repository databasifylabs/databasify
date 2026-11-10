<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Jobs\Databases\UpdateDatabaseWrapperJob;
use App\Models\Database;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:update-wrappers')]
#[Description('Queue an update to the newest compatible databasify wrapper for every ready database')]
final class UpdateDatabaseWrappersCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Database::query()
            ->ready()
            ->withoutRunningFlow()
            ->lazyById()
            ->each(function (Database $database) use (&$queued): void {
                dispatch(new UpdateDatabaseWrapperJob($database));
                $queued++;
            });

        $this->components->info("Queued {$queued} wrapper checks.");

        return self::SUCCESS;
    }
}
