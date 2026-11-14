<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\EndFailedNodeOperation;
use App\Actions\Databases\Pools\ApplyNodeConnectionPools;
use App\Enums\Queues\QueueGroup;
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
#[Timeout(600)]
#[DeleteWhenMissingModels]
final class ApplyNodeConnectionPoolsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public DatabaseNode $node) {}

    public function handle(ApplyNodeConnectionPools $action): void
    {
        $action->handle($this->node);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedNodeOperation::class)->handle($this->node, 'Apply connection pools', $exception);
    }
}
