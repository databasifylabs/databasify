<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Actions\Databases\Nodes\RedeployNode;
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
final class RedeployNodeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Database $database,
        public DatabaseNode $node,
    ) {}

    public function handle(RedeployNode $action): void
    {
        $action->handle($this->database, $this->node);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->database, "Redeploy {$this->node->displayName()}", $exception);
    }
}
