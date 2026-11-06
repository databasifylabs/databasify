<?php

declare(strict_types=1);

namespace App\Jobs\Servers;

use App\Actions\Servers\ReadServerFacts;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FailsRunningLogs;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

#[Queue(QueueGroup::Monitoring)]
#[Tries(1)]
#[Timeout(120)]
#[UniqueFor(420)]
final class RefreshServerFactsJob implements ShouldBeUnique, ShouldQueue
{
    use FailsRunningLogs, Queueable;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(ReadServerFacts $facts): void
    {
        $before = $this->server->resources();
        $facts->handle($this->server);
        $this->server->flagDatabasesForRetune($before);
    }

    /**
     * @return list<string>
     */
    protected function logNames(): array
    {
        return [ReadServerFacts::LOG_NAME];
    }
}
