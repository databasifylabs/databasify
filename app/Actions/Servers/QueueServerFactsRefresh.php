<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Jobs\Servers\RefreshServerFactsJob;
use App\Models\Server;

final readonly class QueueServerFactsRefresh
{
    public function handle(Server $server): void
    {
        dispatch(new RefreshServerFactsJob($server));
    }
}
