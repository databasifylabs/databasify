<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Certificates\InstallNodeCertificate;
use App\Actions\Databases\Certificates\IssueNodeCertificates;
use App\Actions\Dns\EnsureZoneCertificate;
use App\Engines\EngineClient;
use App\Engines\Requests\EngineInfo;
use App\Engines\Requests\HostDeploy;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Engines\SdkRange;

final readonly class DeployNode
{
    public function __construct(
        private InstallHostBinary $hostBinary,
        private IssueNodeCertificates $certificates,
        private EngineClient $engine,
        private InstallNodeCertificate $certificate,
        private EnsureZoneCertificate $zoneCertificate,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $this->ensurePublicCertificateOf($database);
        $this->certificates->handle($database);
        $this->hostBinary->handle($node);

        $this->engine->send($node, $this->deploymentOf($database, $node), "{$node->server->name}: deploying the engine");
        $this->ensureSupportedSdkOn($database, $node);

        $this->certificate->handle($database->refresh(), $node);
    }

    private function ensurePublicCertificateOf(Database $database): void
    {
        $zone = $database->publicCertificateZone();

        if ($zone === null) {
            return;
        }

        rescue(fn () => $this->zoneCertificate->handle($zone));
    }

    private function ensureSupportedSdkOn(Database $database, DatabaseNode $node): void
    {
        if (! $database->runsSupportedSdk()) {
            return;
        }

        $sdk = $this->engine->send($node, new EngineInfo)->sdk;

        if (SdkRange::allows($sdk)) {
            return;
        }

        throw ImageUnavailable::unsupportedSdk($database->engine()->label, $database->image(), $sdk);
    }

    private function deploymentOf(Database $database, DatabaseNode $node): HostDeploy
    {
        return new HostDeploy($database->image()->pinnedReference(), $database->id, $node->id);
    }
}
