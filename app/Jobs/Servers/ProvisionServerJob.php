<?php

declare(strict_types=1);

namespace App\Jobs\Servers;

use App\Actions\Servers\ProvisionServer;
use App\Enums\Queues\QueueGroup;
use App\Models\Server;
use App\Support\Servers\ProvisionSteps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Provisioning)]
#[Tries(1)]
#[Timeout(1800)]
final class ProvisionServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server, public bool $disablePasswordLogin) {}

    public function handle(ProvisionServer $provision): void
    {
        $provision->handle($this->server, $this->disablePasswordLogin);
    }

    public function failed(?Throwable $exception): void
    {
        $this->server->failSetup();
        $this->server->failRunningLogs(ProvisionSteps::logNames($this->disablePasswordLogin), $exception);
    }
}
