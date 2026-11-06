<?php

declare(strict_types=1);

namespace App\Jobs\Servers;

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
final class CreateServerUserJob implements ShouldQueue
{
    use FailsRunningLogs, Queueable;

    public function __construct(public Server $server, public string $username, public ?string $publicKey) {}

    public function handle(): void
    {
        $this->server->ssh()->exec('ssh.servers.users.create', ['username' => $this->username, 'publicKey' => $this->publicKey], "Create user {$this->username}");
    }

    /**
     * @return list<string>
     */
    protected function logNames(): array
    {
        return ["Create user {$this->username}"];
    }
}
