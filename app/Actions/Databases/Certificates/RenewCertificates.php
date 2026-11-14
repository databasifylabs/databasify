<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Actions\Dns\EnsureZoneCertificate;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class RenewCertificates
{
    public function __construct(
        private EnsureZoneCertificate $zoneCertificate,
        private IssueNodeCertificates $issue,
        private InstallNodeCertificate $install,
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

        $nodes->each(
            fn (DatabaseNode $node) => $this->install->handle($database, $node),
        );
        $database->markReady();
    }
}
