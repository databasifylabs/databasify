<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Jobs\Databases\RecoverClusterJob;
use App\Jobs\Databases\RejoinMembersJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class RecoverDatabase
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
        private DetectQuorum $quorum,
    ) {}

    public function handle(Database $database, bool $othersAreDown = false): void
    {
        $database->ensureNotBusy();
        $this->refresh->handle($database);
        $reachable = $database->reachableMembers();
        $answering = $reachable->count();
        $members = $database->memberCount();

        if ($answering === 0) {
            throw ValidationException::withMessages(['database' => "{$database->name} has no reachable member to recover from."]);
        }

        if (! $othersAreDown && $answering * 2 <= $members) {
            throw ValidationException::withMessages(['database' => "Only {$answering} of {$members} members answer. If the others are really down and not just cut off from Databasify, recover again and confirm they are down."]);
        }

        if (! $this->quorum->handle($database)) {
            $database->markBusy(DatabaseStatus::Recovering);
            dispatch(new RecoverClusterJob($database, $reachable->ids(), $othersAreDown));

            return;
        }

        $outsiders = $reachable->filter(fn (DatabaseNode $node): bool => $node->role === NodeRole::Offline);

        if ($outsiders->isEmpty() || ! $reachable->contains(fn (DatabaseNode $node): bool => $node->isPrimary())) {
            throw ValidationException::withMessages(['database' => "{$database->name} still has a majority, use failover instead."]);
        }

        $database->markBusy(DatabaseStatus::Recovering);
        dispatch(new RejoinMembersJob($database, $outsiders->ids()));
    }
}
