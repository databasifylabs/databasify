<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Ssh\CertificateOutput;

final readonly class IssueNodeCertificate
{
    public function handle(Database $database, DatabaseNode $node): void
    {
        $database->storeNodeCertificates($node, CertificateOutput::parse($this->generatedCertificateOf($database, $node)));
    }

    private function generatedCertificateOf(Database $database, DatabaseNode $node): string
    {
        $primary = $database->primaryServer();

        return $primary->ssh($database)->exec(
            'ssh.databases.certificates.generate',
            $database->provider()->scriptData()->certificates($node->certificateNames(), reuseAuthority: true),
            "{$primary->name}: issuing the certificate of {$node->server->name}",
            secret: true,
        );
    }
}
