<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Servers\ReadServerFacts;
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
final class RetuneDatabaseJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    public function __construct(public Database $database) {}

    public function handle(RollingNodes $rolling, ReadServerFacts $facts, BuildNodeConfig $nodeConfig, RefreshDatabaseStatus $refresh): void
    {
        $database = $this->database;
        $needsRestart = false;

        $rolling->each($database, fn (DatabaseNode $node) => $facts->handle($node->server), failover: false);
        $rolling->each($database, fn (DatabaseNode $node) => $node->driver()->nodes()->configure($nodeConfig->handle($database, $node)), failover: false);
        $rolling->each($database, function (DatabaseNode $node) use (&$needsRestart): void {
            $needsRestart = $node->driver()->config()->tune() !== [] || $needsRestart;
            $node->driver()->config()->apply();
        }, failover: false);

        if ($needsRestart) {
            $rolling->restart($database);
        }

        $database->markRetuned();
        $refresh->handle($database);
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Updating;
    }

    protected function operationName(): string
    {
        return 'Retune for server size';
    }
}
