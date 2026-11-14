<?php

declare(strict_types=1);

namespace App\Jobs\Servers;

use App\Enums\Queues\QueueGroup;
use App\Enums\Servers\ServerOperation;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Provisioning)]
#[Tries(1)]
#[Timeout(1800)]
final class RebootServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        $this->server->ssh()->exec('ssh.servers.os.reboot', log: ServerOperation::Reboot->title());
    }

    public function failed(?Throwable $exception): void
    {
        $this->server->failRunningLogs([ServerOperation::Reboot->title()], $exception);
    }
}
