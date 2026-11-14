<?php

declare(strict_types=1);

namespace App\Jobs\Networks;

use App\Actions\Networks\JoinWireguardNetwork;
use App\Enums\Queues\QueueGroup;
use App\Models\Network;
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
final class JoinWireguardNetworkJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server, public Network $network) {}

    public function handle(JoinWireguardNetwork $join): void
    {
        $join->handle($this->server, $this->network);
    }

    public function failed(?Throwable $exception): void
    {
        $this->server->failRunningLogs([JoinWireguardNetwork::LOG_NAME], $exception);
    }
}
