<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Flows\StartFlow;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Engines\Flows\FlowContext;
use App\Engines\Flows\RecoverClusterFlow;
use App\Engines\Flows\RejoinMembersFlow;
use App\Enums\Databases\NodeRole;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final readonly class RecoverDatabase
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
        private DetectQuorum $quorum,
        private StartFlow $startFlow,
        private RecoverClusterFlow $flow,
        private RejoinMembersFlow $rejoin,
    ) {}

    public function handle(Database $database, bool $othersAreDown = false): void
    {
        $database->ensureNotBusy();
        $this->refresh->handle($database);
        $reachable = $database->reachableMembers();
        $this->ensureRecoverable($database, $reachable->count(), $othersAreDown);

        if ($this->quorum->handle($database)) {
            $this->rejoinOutsidersOf($database, $reachable);

            return;
        }

        $this->startFlow->handle($database, $this->flow, new FlowContext(
            nodeIds: $this->idsOf($reachable),
            force: $othersAreDown,
        ));
    }

    private function ensureRecoverable(Database $database, int $reachable, bool $othersAreDown): void
    {
        $members = $database->memberCount();

        if ($reachable === 0) {
            throw $this->rejection("{$database->name} has no reachable member to recover from.");
        }

        if (! $othersAreDown && $reachable * 2 <= $members) {
            throw $this->rejection("Only {$reachable} of {$members} members answer. If the others are really down and not just cut off from Databasify, recover again and confirm they are down.");
        }
    }

    /**
     * @param  Collection<int, DatabaseNode>  $reachable
     */
    private function rejoinOutsidersOf(Database $database, Collection $reachable): void
    {
        $outsiders = $reachable->filter(fn (DatabaseNode $node): bool => $node->role === NodeRole::Offline);

        if ($outsiders->isEmpty() || ! $reachable->contains(fn (DatabaseNode $node): bool => $node->isPrimary())) {
            throw $this->rejection("{$database->name} still has a majority, use failover instead.");
        }

        $this->startFlow->handle($database, $this->rejoin, new FlowContext(nodeIds: $this->idsOf($outsiders)));
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     * @return list<string>
     */
    private function idsOf(Collection $nodes): array
    {
        return array_values(array_filter($nodes->modelKeys(), is_string(...)));
    }

    private function rejection(string $message): ValidationException
    {
        return ValidationException::withMessages(['database' => $message]);
    }
}
