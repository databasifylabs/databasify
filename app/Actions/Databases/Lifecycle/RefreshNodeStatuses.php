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
use App\Services\Databases\NodeRunner;

final readonly class RefreshNodeStatuses
{
    public function __construct(
        private SyncDatabaseDns $dns,
        private NodeRunner $nodeRunner,
        private ReadNodeStatus $readStatus,
    ) {}

    public function handle(Database $database, DatabaseNodeCollection $nodes): DatabaseNodeCollection
    {
        if ($database->status === DatabaseStatus::Failed) {
            return $database->nodesWithServers();
        }

        $previous = $database->nodesWithServers()->health();

        $statuses = $this->nodeRunner->map(
            $nodes,
            fn (DatabaseNode $node): NodeStatus => $this->readStatus->handle($database, $node),
            NodeStatus::class,
        );
        $anotherAnswersAsPrimary = collect($statuses)->contains(fn (NodeStatus $status): bool => $status->role === NodeRole::Primary);
        $nodes->values()->each(fn (DatabaseNode $node, int $index) => $this->recordStatusOn($node, $statuses[$index] ?? NodeStatus::unreachable(), $anotherAnswersAsPrimary));

        $all = $database->nodesWithServers();
        $this->announceChange($database, $all, $previous, $all->health());
        rescue(fn () => $this->dns->handle($database));

        return $all;
    }

    private function recordStatusOn(DatabaseNode $node, NodeStatus $status, bool $anotherAnswersAsPrimary): void
    {
        if ($status->isUnreachable() && $anotherAnswersAsPrimary && $node->isPrimary()) {
            $node->markUnreachable();

            return;
        }

        $node->recordStatus($status);
    }

    private function announceChange(Database $database, DatabaseNodeCollection $nodes, string $previous, string $health): void
    {
        if ($previous === $health || $database->status->isSettingUp()) {
            return;
        }

        if ($health === 'healthy') {
            event(new DatabaseAlertRaised($database, "{$database->name} is healthy again", 'Every node is back and exactly one primary is serving writes.'));

            return;
        }

        event(new DatabaseAlertRaised($database, "{$database->name} is {$health}", $nodes->problems()));
    }
}
