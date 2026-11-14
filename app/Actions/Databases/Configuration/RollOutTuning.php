<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Servers\ReadServerFacts;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;

final readonly class RollOutTuning
{
    public function __construct(
        private RollingNodes $rolling,
        private ReadServerFacts $facts,
        private BuildNodeConfig $nodeConfig,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function handle(Database $database, string $run): void
    {
        $needsRestart = false;

        $this->rolling->each($database, fn (DatabaseNode $node) => $this->facts->handle($node->server), $run, failover: false, resumable: false);
        $this->rolling->each($database, fn (DatabaseNode $node) => $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node)), $run, failover: false, resumable: false);
        $this->rolling->each($database, function (DatabaseNode $node) use (&$needsRestart): void {
            $needsRestart = $node->driver()->config()->tune() !== [] || $needsRestart;
            $node->driver()->config()->apply();
        }, $run, failover: false, resumable: false);

        if ($needsRestart || $this->rolling->begun($database, $run)) {
            $this->rolling->restart($database, $run);
        }

        $database->markRetuned();
        $this->refresh->handle($database);
        $database->markReady();
    }
}
