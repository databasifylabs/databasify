<?php

declare(strict_types=1);

namespace App\Jobs\Networks;

use App\Actions\Networks\LeaveWireguardNetwork;
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
#[Timeout(600)]
final class LeaveWireguardNetworkJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server, public Network $network) {}

    public function handle(LeaveWireguardNetwork $leave): void
    {
        $leave->handle($this->server, $this->network);
    }

    public function failed(?Throwable $exception): void
    {
        $this->server->failRunningLogs([LeaveWireguardNetwork::LOG_NAME], $exception);
    }
}
