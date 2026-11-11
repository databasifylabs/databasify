<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Engines\EngineClient;
use App\Engines\Requests\ClusterFailover;
use App\Models\Database;
use App\Models\DatabaseNode;
use RuntimeException;

final readonly class PromoteStandby
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
        private EngineClient $engine,
    ) {}

    public function handle(Database $database): void
    {
        $this->refresh->handle($database);
        $members = $database->membersWithServers()->filter(fn (DatabaseNode $node): bool => $node->isAnswering());
        $primary = $members->primary();
        $target = $members->replica();

        throw_if($primary === null || $target === null, RuntimeException::class, 'No healthy primary and standby to switch between.');

        $this->engine->send($primary, new ClusterFailover($target->privateIp()), "moving the primary off {$primary->server->name}");
        $this->refresh->handle($database);
    }
}
