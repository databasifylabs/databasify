<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Certificates\IssueNodeCertificates;
use App\Engines\EngineClient;
use App\Engines\Requests\HostDeploy;
use App\Engines\Requests\TlsInstall;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class DeployNode
{
    public function __construct(
        private InstallHostBinary $hostBinary,
        private IssueNodeCertificates $certificates,
        private EngineClient $engine,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $this->certificates->handle($database);
        $this->hostBinary->handle($node);

        $this->engine->send($node, $this->deploymentOf($database, $node), "{$node->server->name}: deploying the engine");
        $this->engine->send($node, $this->certificateOf($database->refresh(), $node), "{$node->server->name}: installing certificates");
    }

    private function deploymentOf(Database $database, DatabaseNode $node): HostDeploy
    {
        return new HostDeploy($database->image()->pinnedReference(), $database->id, $node->id);
    }

    private function certificateOf(Database $database, DatabaseNode $node): TlsInstall
    {
        return new TlsInstall(
            $database->settings->nodeCertificate($node->id, IssueNodeCertificates::CERTIFICATE) ?? '',
            $database->settings->nodeCertificate($node->id, IssueNodeCertificates::KEY) ?? '',
            $database->settings->certificate(Database::CERTIFICATE_AUTHORITY),
        );
    }
}
