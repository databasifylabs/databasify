<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Enums\Databases\DatabaseStatus;
use App\Jobs\Databases\RebootDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class RebootDatabase
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database): void
    {
        $database->ensureNotBusy();
        $this->ensureServersReady($database);
        $this->ensureHealthy($database);

        $database->markBusy(DatabaseStatus::Rebooting);
        dispatch(new RebootDatabaseJob($database));
    }

    private function ensureServersReady(Database $database): void
    {
        $notReady = $database->nodesWithServers()->first(fn (DatabaseNode $node): bool => ! $node->server->isReady());

        if ($notReady === null) {
            return;
        }

        throw ValidationException::withMessages(['database' => "{$notReady->server->name} is not ready yet."]);
    }

    private function ensureEveryNodeAnswers(Database $database): void
    {
        $silent = $database->nodesWithServers()->first(fn (DatabaseNode $node): bool => ! $node->isAnswering());

        if ($silent === null) {
            return;
        }

        throw ValidationException::withMessages(['database' => "{$silent->server->name} does not answer, bring every node back before rebooting its servers one by one."]);
    }

    private function ensureHealthy(Database $database): void
    {
        $nodes = $this->refresh->handle($database);

        if (! $database->hasStandby()) {
            return;
        }

        if (! $nodes->isHealthy()) {
            throw ValidationException::withMessages(['database' => "{$database->name} is degraded, bring every node back before rebooting its servers one by one."]);
        }

        $this->ensureEveryNodeAnswers($database);
    }
}
