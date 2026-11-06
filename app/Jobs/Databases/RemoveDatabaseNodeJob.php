<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Nodes\LeaveDatabaseNode;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(3600)]
final class RemoveDatabaseNodeJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public DatabaseNode $node, public bool $force = false) {}

    public function handle(LeaveDatabaseNode $action): void
    {
        $action->handle($this->node, $this->force);
    }

    protected function operatedDatabase(): Database
    {
        return $this->node->database;
    }
}
