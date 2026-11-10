<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Certificates\InstallNodeCertificate;
use App\Actions\Databases\Certificates\IssueNodeCertificates;
use App\Engines\EngineClient;
use App\Engines\Requests\HostDeploy;
use App\Models\Database;
use App\Models\DatabaseNode;

final readonly class DeployNode
{
    public function __construct(
        private InstallHostBinary $hostBinary,
        private IssueNodeCertificates $certificates,
        private EngineClient $engine,
        private InstallNodeCertificate $certificate,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $this->certificates->handle($database);
        $this->hostBinary->handle($node);

        $this->engine->send($node, $this->deploymentOf($database, $node), "{$node->server->name}: deploying the engine");
        $this->certificate->handle($database->refresh(), $node);
    }

    private function deploymentOf(Database $database, DatabaseNode $node): HostDeploy
    {
        return new HostDeploy($database->image()->pinnedReference(), $database->id, $node->id);
    }
}
