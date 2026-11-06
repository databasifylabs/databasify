<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Jobs\Databases\RefreshRecoveryStatusJob;
use App\Models\PointInTimeRecovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('databases:refresh-recovery')]
#[Description('Queue an archive check for every database with point-in-time recovery')]
final class RefreshRecoveryStatusCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        PointInTimeRecovery::query()
            ->whereHas('database', fn (Builder $databases) => $databases->operational())
            ->lazyById()
            ->each(function (PointInTimeRecovery $recovery) use (&$queued): void {
                dispatch(new RefreshRecoveryStatusJob($recovery));
                $queued++;
            });

        $this->components->info("Queued {$queued} archive checks.");

        return self::SUCCESS;
    }
}
