<?php

declare(strict_types=1);

namespace App\Actions\Databases\Lifecycle;

use App\Actions\Databases\Certificates\IssueCertificates;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Nodes\ConfigureDatabaseNode;
use App\Actions\Databases\Nodes\StartDatabaseNode;
use App\Actions\Databases\Nodes\StopDatabaseNode;
use App\Actions\Databases\Recovery\ReconcileRestoredAccounts;
use App\Actions\Databases\Recovery\RestoreArchive;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;
use Throwable;

final readonly class BootstrapDatabase
{
    public function __construct(
        private ConfigureDatabaseNode $configure,
        private ApplyNodeFirewall $firewall,
        private StartDatabaseNode $start,
        private RestoreArchive $restore,
        private IssueCertificates $certificates,
        private RefreshDatabaseStatus $refresh,
        private NodeRunner $nodeRunner,
        private StopDatabaseNode $stop,
        private ReconcileRestoredAccounts $restoredAccounts,
    ) {}

    public function handle(Database $database, bool $clearFirst = false): void
    {
        try {
            $this->bootstrap($database, $clearFirst);
        } catch (Throwable $exception) {
            $database->markFailed();

            throw $exception;
        }
    }

    private function bootstrap(Database $database, bool $clearFirst): void
    {
        if ($clearFirst) {
            $database->nodes->each(fn (DatabaseNode $node) => $this->clearLeftoversOn($database, $node));
        }

        $this->certificates->handle($database);
        $this->configureNodesOf($database);
        $this->restore->handle($database);
        $this->openFirewallsOf($database);
        $this->startAll($database);
        $database->provider()->nodes()->finishBootstrap($database->seed());
        $this->refresh->handle($database);
        $this->restoredAccounts->handle($database);
    }

    private function configureNodesOf(Database $database): void
    {
        $this->nodeRunner->each(
            $database->nodes,
            fn (DatabaseNode $node) => $this->configure->handle($database, $node, fresh: true),
            "writing configuration on {$database->nodes->count()} nodes",
        );
    }

    private function openFirewallsOf(Database $database): void
    {
        $sources = $database->trustedCidrs();
        $this->nodeRunner->each(
            $database->nodes,
            fn (DatabaseNode $node) => $this->firewall->handle($database, $node, $sources),
            "opening the firewall on {$database->nodes->count()} nodes",
        );
    }

    private function startAll(Database $database): void
    {
        $clustered = $database->isClustered();

        $database->nodes->each(fn (DatabaseNode $node) => $this->start->handle(
            $database,
            $node,
            expectPrimary: $node->isSeed(),
            joining: $clustered && ! $node->isSeed(),
            bootstrapsCluster: $clustered && $node->isSeed(),
        ));
    }

    private function clearLeftoversOn(Database $database, DatabaseNode $node): void
    {
        $this->stop->handle($database, $node, true, "{$node->server->name}: clearing what the failed setup left");
    }
}
