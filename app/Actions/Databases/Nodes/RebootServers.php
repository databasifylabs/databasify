<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;

final readonly class RebootServers
{
    public function __construct(
        private RollingNodes $rolling,
        private RebootServer $reboot,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database, string $run): void
    {
        $this->rolling->each($database, fn (DatabaseNode $node, bool $expectPrimary) => $this->reboot->handle($node, $run, $expectPrimary), $run, withReadOnly: false);

        $this->refresh->handle($database);
        $database->markReady();
    }
}
