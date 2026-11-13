<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\DTOs\Databases\IssuedCertificate;
use App\DTOs\Databases\NodeCertificate;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class InstallNodeCertificate
{
    public function handle(Database $database, DatabaseNode $node): void
    {
        $certificate = $this->certificateOf($database, $node);

        $node->driver()->nodes()->installTls($certificate->certificate, $certificate->key, $certificate->ca);
        $node->update(['certificate_fingerprint' => IssuedCertificate::fingerprintOf($certificate->certificate)]);
    }

    private function certificateOf(Database $database, DatabaseNode $node): NodeCertificate
    {
        $zone = $database->publicCertificateZone();

        if ($zone?->certificate !== null && $zone->certificate_key !== null) {
            return new NodeCertificate($zone->certificate, $zone->certificate_key, $zone->certificate);
        }

        return new NodeCertificate(
            $database->settings->nodeCertificate($node->id, IssueNodeCertificates::CERTIFICATE) ?? '',
            $database->settings->nodeCertificate($node->id, IssueNodeCertificates::KEY) ?? '',
            $database->settings->certificate(Database::CERTIFICATE_AUTHORITY),
        );
    }
}
