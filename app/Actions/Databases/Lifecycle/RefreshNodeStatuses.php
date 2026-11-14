<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Actions\Databases\Domains\SyncDatabaseDns;
use App\DTOs\Databases\NodeStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Events\DatabaseAlertRaised;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class RefreshNodeStatuses
{
    public function __construct(
        private SyncDatabaseDns $dns,
        private ReadNodeStatus $readStatus,
    ) {}

    public function handle(Database $database, DatabaseNodeCollection $nodes): DatabaseNodeCollection
    {
        if ($database->status === DatabaseStatus::Failed) {
            return $database->nodesWithServers();
        }

        $previous = $database->nodesWithServers()->health();
        $statuses = $nodes->values()->map(fn (DatabaseNode $node): NodeStatus => $this->readStatus->handle($database, $node));
        $anotherAnswersAsPrimary = $statuses->contains(fn (NodeStatus $status): bool => $status->role === NodeRole::Primary);

        $nodes->values()->each(function (DatabaseNode $node, int $index) use ($statuses, $anotherAnswersAsPrimary): void {
            $status = $statuses[$index] ?? NodeStatus::unreachable();
            $status->isUnreachable() && $anotherAnswersAsPrimary && $node->isPrimary() ? $node->markUnreachable() : $node->recordStatus($status);
        });

        $all = $database->nodesWithServers();
        $health = $all->health();

        if ($previous !== $health && ! $database->status->isSettingUp()) {
            event($health === 'healthy'
                ? new DatabaseAlertRaised($database, "{$database->name} is healthy again", 'Every node is back and exactly one primary is serving writes.')
                : new DatabaseAlertRaised($database, "{$database->name} is {$health}", $all->problems()));
        }

        rescue(fn () => $this->dns->handle($database));

        return $all;
    }
}
