<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Jobs\Databases\RebootDatabaseJob;
use App\Models\Database;
use App\Models\DatabaseNode;
use Illuminate\Validation\ValidationException;

final readonly class RebootDatabase
{
    public function handle(Database $database): void
    {
        $database->ensureIdle();
        $this->ensureServersReady($database);
        $database->startOperation('Reboot servers');
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
}
