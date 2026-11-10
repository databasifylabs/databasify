<?php

declare(strict_types=1);

namespace App\Console\Commands\Databases;

use App\Jobs\Databases\AdvanceDatabaseFlow;
use App\Models\DatabaseFlow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('databases:advance-flows')]
#[Description('Queue the next step of every running database flow')]
final class AdvanceDatabaseFlowsCommand extends Command
{
    public function handle(): int
    {
        DatabaseFlow::query()->running()->lazyById()
            ->each(fn (DatabaseFlow $flow) => dispatch(new AdvanceDatabaseFlow($flow)));

        return self::SUCCESS;
    }
}
