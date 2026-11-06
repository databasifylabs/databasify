<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Certificates\ReissueStaleCertificates;
use App\Actions\Databases\Certificates\ReloadNodeCertificates;
use App\Actions\Databases\Failover\PromoteStandby;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class RedeployDatabaseNode
{
    public function __construct(
        private ReissueStaleCertificates $certificates,
        private DeployDatabaseNode $deploy,
        private ReloadNodeCertificates $reload,
        private RefreshDatabaseStatus $refresh,
        private PromoteStandby $promote,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $reissued = $this->certificates->handle($database);
        $this->handOffPrimaryFrom($database, $node);
        $this->deploy->handle($database, $node);

        if ($reissued) {
            $this->reload->handle($database, $node);
        }

        $this->refresh->handle($database);
    }

    private function handOffPrimaryFrom(Database $database, DatabaseNode $node): void
    {
        if ($node->isReadOnly() || ! $database->hasStandby()) {
            return;
        }

        $this->refresh->handle($database);
        $hasStandby = $database->nodes()->members()->get()->contains(fn (DatabaseNode $member): bool => $member->isReplica());

        if ($node->refresh()->role !== NodeRole::Primary || ! $hasStandby) {
            return;
        }

        $this->promote->handle($database);
    }
}
