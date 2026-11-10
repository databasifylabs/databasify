<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Actions\Dns\EnsureZoneCertificate;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;

final readonly class RenewCertificates
{
    public function __construct(
        private EnsureZoneCertificate $zoneCertificate,
        private IssueNodeCertificates $issue,
        private InstallNodeCertificate $install,
        private NodeRunner $nodeRunner,
    ) {}

    public function handle(Database $database): void
    {
        $zone = $database->publicCertificateZone();

        if ($zone !== null) {
            $this->zoneCertificate->handle($zone);
        }

        $this->issue->handle($database);
        $database->refresh();
        $nodes = $database->nodesWithServers();

        $this->nodeRunner->each(
            $nodes,
            fn (DatabaseNode $node) => $this->install->handle($database, $node),
            "installing certificates on {$nodes->count()} nodes",
        );
    }
}
