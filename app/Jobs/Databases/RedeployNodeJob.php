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
final class RedeployNodeJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(
        public Database $database,
        public DatabaseNode $node,
    ) {}

    public function handle(RollingNodes $rolling, DeployNode $deploy, RefreshDatabaseStatus $refresh): void
    {
        $rolling->each($this->database, function (DatabaseNode $node, bool $expectPrimary) use ($rolling, $deploy): void {
            $deploy->handle($node->database, $node);
            $rolling->backOnline($node, $expectPrimary);
        }, only: $this->node);

        $refresh->handle($this->database);
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Scaling;
    }

    protected function operationName(): string
    {
        return "Redeploy {$this->node->displayName()}";
    }
}
