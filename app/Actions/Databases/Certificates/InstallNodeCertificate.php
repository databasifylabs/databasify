<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Engines\EngineClient;
use App\Engines\Requests\TlsInstall;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class InstallNodeCertificate
{
    public function __construct(private EngineClient $engine) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $this->engine->send($node, $this->certificateOf($database, $node), "{$node->server->name}: installing certificates");
    }

    private function certificateOf(Database $database, DatabaseNode $node): TlsInstall
    {
        $zone = $database->publicCertificateZone();

        if ($zone?->certificate !== null && $zone->certificate_key !== null) {
            return new TlsInstall($zone->certificate, $zone->certificate_key, $zone->certificate);
        }

        return new TlsInstall(
            $database->settings->nodeCertificate($node->id, IssueNodeCertificates::CERTIFICATE) ?? '',
            $database->settings->nodeCertificate($node->id, IssueNodeCertificates::KEY) ?? '',
            $database->settings->certificate(Database::CERTIFICATE_AUTHORITY),
        );
    }
}
