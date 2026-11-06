<?php

declare(strict_types=1);

namespace App\Console\Commands\Servers;

use App\Actions\Servers\ReleaseAbandonedWork;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('servers:release-abandoned-work')]
#[Description('Fail server logs and release database operations whose worker died')]
final class ReleaseAbandonedWorkCommand extends Command
{
    public function handle(ReleaseAbandonedWork $release): int
    {
        $release->handle();
        $this->components->info('Released abandoned logs and operations.');

        return self::SUCCESS;
    }
}
