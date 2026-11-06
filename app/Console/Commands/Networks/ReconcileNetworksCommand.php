<?php

declare(strict_types=1);

namespace App\Console\Commands\Networks;

use App\Jobs\Networks\CheckWireguardNetworkJob;
use App\Models\Network;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('networks:reconcile')]
#[Description('Queue a health check for every Databasify network with members')]
final class ReconcileNetworksCommand extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Network::query()->wireguard()->withMembers()
            ->lazyById()
            ->each(function (Network $network) use (&$queued): void {
                dispatch(new CheckWireguardNetworkJob($network));
                $queued++;
            });

        $this->components->info("Queued {$queued} network checks.");

        return self::SUCCESS;
    }
}
