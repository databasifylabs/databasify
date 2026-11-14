<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Failover\RejoinMembers;
use App\Actions\Databases\Lifecycle\EndFailedOperation;
use App\Enums\Queues\QueueGroup;
use App\Models\Database;
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
final class RejoinMembersJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $nodeIds
     */
    public function __construct(
        public Database $database,
        public array $nodeIds,
    ) {}

    public function handle(RejoinMembers $action): void
    {
        $action->handle($this->database, $this->nodeIds);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->database, 'Rejoin members', $exception);
    }
}
