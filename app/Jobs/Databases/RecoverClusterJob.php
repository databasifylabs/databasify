<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Failover\RecoverCluster;
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
final class RecoverClusterJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $nodeIds
     */
    public function __construct(
        public Database $database,
        public array $nodeIds,
        public bool $othersDown,
    ) {}

    public function handle(RecoverCluster $action): void
    {
        $action->handle($this->database, $this->nodeIds, $this->othersDown);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(EndFailedOperation::class)->handle($this->database, 'Recover from a lost primary', $exception);
    }
}
