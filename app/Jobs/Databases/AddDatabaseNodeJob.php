<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Nodes\JoinDatabaseNode;
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
#[Timeout(22000)]
final class AddDatabaseNodeJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public Database $database, public DatabaseNode $node) {}

    public function handle(JoinDatabaseNode $action): void
    {
        $action->handle($this->database, $this->node);
    }
}
