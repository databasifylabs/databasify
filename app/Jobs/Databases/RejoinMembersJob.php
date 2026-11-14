<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\DeployNode;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(7200)]
#[DeleteWhenMissingModels]
final class RejoinMembersJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    /**
     * @param  list<string>  $nodeIds
     */
    public function __construct(
        public Database $database,
        public array $nodeIds,
    ) {}

    public function handle(DeployNode $deploy, RollingNodes $rolling, RefreshDatabaseStatus $refresh): void
    {
        DatabaseNode::query()->with('server', 'database')->findMany($this->nodeIds)->sortBy('position')->each(function (DatabaseNode $node) use ($deploy, $rolling): void {
            $deploy->handle($node->database, $node);
            $rolling->backOnline($node, expectPrimary: false);
        });

        $refresh->handle($this->database);
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Recovering;
    }

    protected function operationName(): string
    {
        return 'Rejoin members';
    }
}
