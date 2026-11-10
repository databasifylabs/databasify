<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Flows\StartFlow;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Engines\Flows\FlowContext;
use App\Engines\Flows\RecoverClusterFlow;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class RecoverDatabase
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
        private DetectQuorum $quorum,
        private StartFlow $startFlow,
        private RecoverClusterFlow $flow,
    ) {}

    public function handle(Database $database, bool $othersAreDown = false): void
    {
        $database->ensureIdle();
        $this->refresh->handle($database);
        $reachable = $database->reachableMembers();
        $this->ensureRecoverable($database, $reachable->count(), $othersAreDown);

        $this->startFlow->handle($database, $this->flow, new FlowContext(
            nodeIds: array_values(array_filter($reachable->modelKeys(), is_string(...))),
            force: $othersAreDown || $reachable->count() === $database->memberCount(),
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

        if ($this->quorum->handle($database)) {
            throw $this->rejection("{$database->name} still has a majority, use failover instead.");
        }
    }

    private function rejection(string $message): ValidationException
    {
        return ValidationException::withMessages(['database' => $message]);
    }
}
