<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Nodes\JoinNode;
use App\Actions\Databases\Nodes\UndoNodeJoin;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(7200)]
#[DeleteWhenMissingModels]
final class AddNodeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Database $database,
        public DatabaseNode $node,
    ) {}

    public function handle(JoinNode $action): void
    {
        $action->handle($this->database, $this->node);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(UndoNodeJoin::class)->handle($this->database, $this->node);
        resolve(EndFailedOperation::class)->handle($this->database, "Add {$this->node->kind->label()} node {$this->node->displayName()}", $exception);
    }
}
