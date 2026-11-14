<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Models\Database;
use App\Models\DatabaseNode;
use RuntimeException;

final readonly class PromoteStandby
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database): void
    {
        $this->refresh->handle($database);
        $members = $database->membersWithServers()->filter(fn (DatabaseNode $node): bool => $node->isAnswering());
        $primary = $members->primary();
        $target = $members->replica();

        throw_if($primary === null || $target === null, RuntimeException::class, 'No healthy primary and standby to switch between.');

        $primary->driver()->cluster()->failover($target->privateIp());
        $this->refresh->handle($database);
    }
}
