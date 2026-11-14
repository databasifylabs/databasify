<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RemoveNodeJob;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class RemoveDatabaseNode
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
        private EnsureNodesSized $sized,
    ) {}

    public function handle(DatabaseNode $node, bool $force = false): void
    {
        $database = $node->database;
        $database->ensureIdle();
        $this->sized->handle($database, without: $node);
        $this->refresh->handle($database);
        $node->refresh();

        if (! $node->isReadOnly() && $database->memberCount() === 1) {
            throw ValidationException::withMessages(['node' => "{$node->server->name} is the only member left."]);
        }

        $remaining = $database->nodesOtherThan($node);
        $standby = $node->isPrimary() ? $remaining->first(fn (DatabaseNode $peer): bool => $peer->isMember() && $peer->isReplica()) : null;

        $database->markBusy(DatabaseStatus::Scaling);
        dispatch(new RemoveNodeJob($database, $node, $standby, $force));
    }
}
