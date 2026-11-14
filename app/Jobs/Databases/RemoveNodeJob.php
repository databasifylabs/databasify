<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Nodes\TakeNodeOut;
use App\Enums\Queues\QueueGroup;
use App\Exceptions\Databases\LastNodeDown;
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
#[Timeout(3600)]
#[DeleteWhenMissingModels]
final class RemoveNodeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Database $database,
        public DatabaseNode $node,
        public ?DatabaseNode $standby,
        public bool $force,
    ) {}

    public function handle(TakeNodeOut $action): void
    {
        $action->handle($this->database, $this->node, $this->standby, $this->force);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->database, "Remove {$this->node->displayName()}", $exception, markFailed: $exception instanceof LastNodeDown);
    }
}
