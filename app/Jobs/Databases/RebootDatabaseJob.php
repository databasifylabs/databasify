<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Nodes\RebootNodes;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(22000)]
final class RebootDatabaseJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public Database $database) {}

    public function handle(RebootNodes $action): void
    {
        $action->handle($this->database);
    }
}
