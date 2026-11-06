<?php

declare(strict_types=1);

namespace App\Jobs\Networks;

use App\Actions\Networks\JoinWireguardNetwork;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FailsRunningLogs;
use App\Models\Network;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Provisioning)]
#[Tries(1)]
#[Timeout(900)]
final class JoinWireguardNetworkJob implements ShouldQueue
{
    use FailsRunningLogs, Queueable;

    public function __construct(public Server $server, public Network $network) {}

    public function handle(JoinWireguardNetwork $join): void
    {
        $join->handle($this->server, $this->network);
    }

    /**
     * @return list<string>
     */
    protected function logNames(): array
    {
        return [JoinWireguardNetwork::LOG_NAME];
    }
}
