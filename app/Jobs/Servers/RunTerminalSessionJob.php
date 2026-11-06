<?php

declare(strict_types=1);

namespace App\Jobs\Servers;

use App\Actions\Servers\RunTerminalSession;
use App\Enums\Queues\QueueGroup;
use App\Models\Server;
use App\Services\Ssh\TerminalSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Terminals)]
#[Tries(1)]
#[Timeout(7260)]
final class RunTerminalSessionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server, public TerminalSession $session, public int $columns, public int $rows) {}

    public function handle(RunTerminalSession $run): void
    {
        $run->handle($this->server, $this->session, $this->columns, $this->rows);
    }
}
