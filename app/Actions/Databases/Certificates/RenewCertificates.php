<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class RenewCertificates
{
    public function __construct(
        private ReissueStaleCertificates $reissue,
        private ReloadNodeCertificates $reload,
        private NodeRunner $nodeRunner,
    ) {}

    public function handle(Database $database): void
    {
        $this->reissue->handle($database);
        $nodes = $database->nodesWithServers();
        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $this->reload->handle($database, $node),
            "reloading certificates on {$nodes->count()} nodes",
        );
    }
}
