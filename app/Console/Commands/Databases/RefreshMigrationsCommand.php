<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Jobs\Databases\RefreshMigrationJob;
use App\Models\DatabaseMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:refresh-migrations')]
#[Description('Queue a progress check for every active migration')]
final class RefreshMigrationsCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        DatabaseMigration::query()->active()
            ->lazyById()
            ->each(function (DatabaseMigration $migration) use (&$queued): void {
                dispatch(new RefreshMigrationJob($migration));
                $queued++;
            });

        $this->components->info("Queued {$queued} migration checks.");

        return self::SUCCESS;
    }
}
