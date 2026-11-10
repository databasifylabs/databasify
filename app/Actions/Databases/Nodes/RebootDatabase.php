<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Flows\StartFlow;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Engines\Flows\RebootNodeFlow;
use App\Engines\Flows\RollingSteps;
use App\Enums\Databases\DatabaseStatus;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class RebootDatabase
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
        private StartFlow $startFlow,
        private RebootNodeFlow $flow,
    ) {}

    public function handle(Database $database): void
    {
        $database->ensureIdle();
        $this->ensureServersReady($database);
        $this->ensureHealthy($database);

        $this->startFlow->handle($database, $this->flow, RollingSteps::contextFor($database));
    }

    private function ensureServersReady(Database $database): void
    {
        $notReady = $database->nodesWithServers()->first(fn (DatabaseNode $node): bool => ! $node->server->isReady());

        if ($notReady === null) {
            return;
        }

        throw ValidationException::withMessages(['database' => "{$notReady->server->name} is not ready yet."]);
    }

    private function ensureHealthy(Database $database): void
    {
        $status = $this->refresh->handle($database);

        if ($status === DatabaseStatus::Ready || ! $database->hasStandby()) {
            return;
        }

        throw ValidationException::withMessages(['database' => "{$database->name} is {$status->value}, bring every node back before rebooting its servers one by one."]);
    }
}
