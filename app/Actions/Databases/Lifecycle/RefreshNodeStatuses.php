<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Actions\Databases\Domains\SyncDatabaseDns;
use App\DTOs\Databases\NodeStatus;
use App\Enums\Databases\DatabaseStatus;
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

    public function handle(Database $database, DatabaseNodeCollection $nodes): DatabaseStatus
    {
        $previous = $database->status;

        if ($database->hasFailed()) {
            return $previous;
        }

        $statuses = $this->nodeRunner->map(
            $nodes,
            fn (DatabaseNode $node): NodeStatus => $this->readStatus->handle($database, $node),
            NodeStatus::class,
        );
        $nodes->values()->each(fn (DatabaseNode $node, int $index) => $node->recordStatus($statuses[$index] ?? NodeStatus::unreachable()));

        $all = $database->nodesWithServers();
        $status = $all->healthStatus();
        $database->recordStatus($status);
        $this->announceChange($database, $all, $previous, $status);
        rescue(fn () => $this->dns->handle($database));

        return $status;
    }

    private function announceChange(Database $database, DatabaseNodeCollection $nodes, DatabaseStatus $previous, DatabaseStatus $status): void
    {
        if ($previous === $status || ! $previous->isServing()) {
            return;
        }

        if ($status === DatabaseStatus::Ready) {
            event(new DatabaseAlertRaised($database, "{$database->name} is healthy again", 'Every node is back and exactly one primary is serving writes.'));

            return;
        }

        event(new DatabaseAlertRaised($database, "{$database->name} is {$status->value}", $nodes->problems()));
    }
}
