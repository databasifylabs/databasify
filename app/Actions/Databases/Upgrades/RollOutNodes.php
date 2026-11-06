<?php

declare(strict_types=1);

namespace App\Actions\Databases\Upgrades;

use App\Actions\Databases\Certificates\ReissueStaleCertificates;
use App\Actions\Databases\Certificates\ReloadNodeCertificates;
use App\Actions\Databases\Failover\PromoteStandby;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Nodes\ConfigureDatabaseNode;
use App\Actions\Databases\Nodes\DeployDatabaseNode;
use App\Actions\Databases\Nodes\PullEngineImage;
use App\DTOs\Databases\EngineImage;
use App\Models\Collections\DatabaseNodeCollection;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;
use RuntimeException;

final readonly class RollOutNodes
{
    public function __construct(
        private ReissueStaleCertificates $certificates,
        private DeployDatabaseNode $deploy,
        private ConfigureDatabaseNode $configure,
        private ReloadNodeCertificates $reload,
        private PromoteStandby $promote,
        private RefreshDatabaseStatus $refresh,
        private PullEngineImage $pull,
        private NodeRunner $nodeRunner,
    ) {}

    public function handle(Database $database, bool $installUpdate): void
    {
        $target = $installUpdate ? $database->availableImage() : null;

        $this->rollOutNodesOf($database, $target);

        if ($target !== null) {
            $database->recordInstalledImage($target);
        }

        $this->refresh->handle($database);
        $database->provider()->accounts()?->regrantReplicationToDefaultUser();
    }

    private function rollOutNodesOf(Database $database, ?EngineImage $target): void
    {
        $this->refresh->handle($database);
        $reload = $this->certificates->handle($database);
        $nodes = $database->nodesWithServers();
        $primary = $nodes->primary() ?? throw new RuntimeException("{$database->name} has no healthy primary, rollout stopped.");
        $database->provider()->nodes()->syncConfig($primary);
        $this->pullOn($nodes, $database, $target);
        $image = $target ?? $database->image();

        $this->nodeRunner->inGroupsOf(
            $nodes->readOnly(),
            fn (DatabaseNode $node) => $this->rollOut($database, $node, $image, $reload, expectPrimary: false),
            'rolling out read-only nodes',
        );
        $this->rollOutMembersOf($database, $nodes->standbysBesides($primary), $primary, $image, $reload);
    }

    private function pullOn(DatabaseNodeCollection $nodes, Database $database, ?EngineImage $target): void
    {
        if ($target === null) {
            return;
        }

        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $this->pull->handle($database, $node, $target),
            "pulling the new image on {$nodes->count()} nodes",
        );
    }

    private function rollOutMembersOf(Database $database, DatabaseNodeCollection $standbys, DatabaseNode $primary, EngineImage $image, bool $reload): void
    {
        $standbys->each(fn (DatabaseNode $node) => $this->rollOut($database, $node, $image, $reload, expectPrimary: false));

        if ($standbys->isEmpty() || $this->configuredWithoutRestart($database, $primary, $image)) {
            $this->rollOut($database, $primary, $image, $reload, expectPrimary: true);

            return;
        }

        $this->promote->handle($database);
        $this->rollOut($database, $primary->refresh(), $image, $reload, expectPrimary: false);
    }

    private function configuredWithoutRestart(Database $database, DatabaseNode $primary, EngineImage $image): bool
    {
        $nodes = $database->provider()->nodes();

        if (! $nodes->skipsRestartOnSameConfig() || ! $nodes->runsImage($primary, $image)) {
            return false;
        }

        $this->configure->handle($database, $primary, image: $image);

        return $nodes->runsCurrentConfig($primary);
    }

    private function rollOut(Database $database, DatabaseNode $node, EngineImage $image, bool $reload, bool $expectPrimary): void
    {
        $this->deploy->handle($database, $node, $expectPrimary, image: $image);

        if ($reload) {
            $this->reload->handle($database, $node);
        }
    }
}
