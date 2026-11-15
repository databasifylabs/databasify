<?php

declare(strict_types=1);

namespace App\Jobs\Servers;

use App\Actions\Providers\LaunchProviderServer;
use App\Enums\Queues\QueueGroup;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Provisioning)]
#[Tries(1)]
#[Timeout(900)]
final class LaunchProviderServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server) {}

    public function handle(LaunchProviderServer $launch): void
    {
        $launch->handle($this->server);
    }

    public function failed(?Throwable $exception): void
    {
        $this->server->failSetup();
        $this->server->failRunningLogs($this->server->serverProvider === null ? [] : LaunchProviderServer::logNamesFor($this->server->serverProvider), $exception);
    }
}
