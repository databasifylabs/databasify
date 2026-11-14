<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\LearnEngine;
use App\Actions\Databases\Nodes\LearnLogUnits;
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
final class UpgradeDatabaseJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(
        public Database $database,
        public DatabaseStatus $status = DatabaseStatus::Upgrading,
    ) {}

    public function handle(
        RollingNodes $rolling,
        DeployNode $deploy,
        BuildNodeConfig $nodeConfig,
        LearnEngine $learn,
        LearnLogUnits $logUnits,
        RefreshDatabaseStatus $refresh,
    ): void {
        $database = $this->database;

        $rolling->each($database, function (DatabaseNode $node, bool $expectPrimary) use ($database, $rolling, $deploy, $nodeConfig): void {
            $deploy->handle($database, $node);
            $node->driver()->nodes()->configure($nodeConfig->handle($database, $node));
            $rolling->backOnline($node, $expectPrimary);
        });

        $refresh->handle($database);
        $learn->handle($database, $database->primaryOrSeed());
        $logUnits->handle($database, $database->primaryOrSeed());
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return $this->status;
    }

    protected function operationName(): string
    {
        return "Install {$this->database->engine()->label} {$this->database->image->release}";
    }
}
