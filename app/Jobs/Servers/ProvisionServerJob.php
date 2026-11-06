<?php

declare(strict_types=1);

namespace App\Jobs\Servers;

use App\Actions\Servers\ProvisionServer;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FailsRunningLogs;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Provisioning)]
#[Tries(1)]
#[Timeout(1800)]
final class ProvisionServerJob implements ShouldQueue
{
    use FailsRunningLogs, Queueable;

    public function __construct(public Server $server, public bool $disablePasswordLogin) {}

    public function handle(ProvisionServer $provision): void
    {
        $provision->handle($this->server, $this->disablePasswordLogin);
    }

    /**
     * @return list<string>
     */
    protected function logNames(): array
    {
        return ProvisionServer::logNames($this->disablePasswordLogin);
    }
}
