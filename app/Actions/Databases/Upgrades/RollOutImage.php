<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Actions\Databases\Accounts\GrantMigrationSourceRoles;
use App\Actions\Databases\Extensions\PruneExtensionPackageFiles;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\BuildNodeConfig;
use App\Actions\Databases\Nodes\DeployNode;
use App\Actions\Databases\Nodes\LearnEngine;
use App\Actions\Databases\Nodes\LearnLogUnits;
use App\Enums\Engines\Feature;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\Schema;
use App\Services\Databases\RollingNodes;
use App\Support\Databases\ExtensionCache;

final readonly class RollOutImage
{
    public function __construct(
        private RollingNodes $rolling,
        private DeployNode $deploy,
        private BuildNodeConfig $nodeConfig,
        private LearnEngine $learn,
        private LearnLogUnits $logUnits,
        private RefreshDatabaseStatus $refresh,
        private GrantMigrationSourceRoles $grantSourceRoles,
        private PruneExtensionPackageFiles $prune,
        private ExtensionCache $cache,
    ) {}

    public function handle(Database $database, string $run): void
    {
        $this->rolling->each($database, function (DatabaseNode $node, bool $expectPrimary) use ($database, $run): void {
            $this->deploy->handle($database, $node);
            $node->driver()->nodes()->configure($this->nodeConfig->handle($database, $node));
            $this->rolling->backOnline($node, $run, $expectPrimary);
        }, $run);

        $this->refresh->handle($database);
        $this->learn->handle($database, $database->primaryOrSeed());
        $this->logUnits->handle($database, $database->primaryOrSeed());
        $this->grantSourceRoles->handle($database);

        if ($database->offers(Feature::Extensions)) {
            $database->driver()->extensions()->allowInstalls();
            $this->prune->handle($database);
            $database->schemas()->get()->each(fn (Schema $schema) => $this->cache->forget($database, $schema->name));
        }

        $database->markReady();
    }
}
