<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Jobs\Databases\RecoverDatabaseJob;
use App\Models\Database;
use Illuminate\Validation\ValidationException;

final readonly class RecoverDatabase
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
        private DetectQuorum $quorum,
    ) {}

    public function handle(Database $database, bool $othersAreDown = false): void
    {
        $database->ensureIdle();
        $this->refresh->handle($database);
        $this->ensureRecoverable($database, $othersAreDown);

        $database->startOperation('Recover from a lost primary');
        dispatch(new RecoverDatabaseJob($database, $othersAreDown));
    }

    private function ensureRecoverable(Database $database, bool $othersAreDown): void
    {
        $reachable = $database->reachableMembers()->count();
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
