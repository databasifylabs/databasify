<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Flows\StartFlow;
use App\Engines\Flows\FlowContext;
use App\Engines\Flows\RemoveNodeFlow;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class RemoveDatabaseNode
{
    public function __construct(
        private StartFlow $startFlow,
        private RemoveNodeFlow $flow,
    ) {}

    public function handle(DatabaseNode $node, bool $force = false): void
    {
        $database = $node->database;
        $database->ensureIdle();

        if (! $node->isReadOnly() && $database->memberCount() === 1) {
            throw ValidationException::withMessages(['node' => "{$node->server->name} is the only member left."]);
        }

        $remaining = $database->nodesOtherThan($node);
        $standby = $node->isPrimary() ? $remaining->first(fn (DatabaseNode $peer): bool => $peer->isMember() && $peer->isReplica()) : null;

        $this->startFlow->handle($database, $this->flow, new FlowContext(
            nodeIds: array_values(array_filter($remaining->modelKeys(), is_string(...))),
            targetNodeId: $node->id,
            failoverTo: $standby?->id,
            force: $force,
        ));
    }
}
