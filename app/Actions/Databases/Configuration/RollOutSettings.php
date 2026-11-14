<?php

declare(strict_types=1);

namespace App\Actions\Databases\Configuration;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Databases\Recovery\RefreshRecoveryStatus;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\RollingNodes;

final readonly class RollOutSettings
{
    public function __construct(
        private RollingNodes $rolling,
        private BuildNodeConfig $nodeConfig,
        private RefreshDatabaseStatus $refresh,
        private RefreshRecoveryStatus $recovery,
    ) {}

    public function handle(Database $database, bool $archiveChanged, string $run): void
    {
        $primary = $database->primaryOrSeed();

        $this->rolling->each($database, fn (DatabaseNode $node) => $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node)), $run, failover: false, resumable: false);
        $primary->driver()->config()->sync();
        $this->rolling->each($database, fn (DatabaseNode $node) => $node->driver()->config()->apply(), $run, failover: false, resumable: false);

        if ($primary->driver()->config()->pendingRestart() !== [] || $this->rolling->begun($database, $run)) {
            $this->rolling->restart($database, $run);
        }

        $this->refresh->handle($database);
        $archive = $database->refresh()->pointInTimeRecovery;

        if ($archiveChanged && $archive !== null) {
            $this->recovery->handle($archive, backUpNow: true);
        }
        $database->markReady();
    }
}
