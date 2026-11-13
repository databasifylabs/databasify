<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Certificates\InstallNodeCertificate;
use App\Actions\Databases\Certificates\IssueNodeCertificates;
use App\Actions\Dns\EnsureZoneCertificate;
use App\Engines\Runtime\Runtime;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Support\Engines\SdkRange;

final readonly class DeployNode
{
    public function __construct(
        private InstallHostBinary $hostBinary,
        private IssueNodeCertificates $certificates,
        private Runtime $runtime,
        private InstallNodeCertificate $certificate,
        private EnsureZoneCertificate $zoneCertificate,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $this->ensurePublicCertificateOf($database);
        $this->certificates->handle($database);
        $this->hostBinary->handle($node);

        $engine = $this->runtime->deploy($node, $database->image()->pinnedReference(), $database->facts()->hostProfile);
        $this->ensureBuiltFor($database, $engine);
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

        $sdk = $this->runtime->engineInfo($node)->sdk;

        if (SdkRange::allows($sdk)) {
            return;
        }

        throw ImageUnavailable::unsupportedSdk($database->engine()->label, $database->image(), $sdk);
    }

    private function ensureBuiltFor(Database $database, string $engine): void
    {
        if ($engine === $database->engine) {
            return;
        }

        throw ImageUnavailable::builtForAnotherEngine($database->engine()->label, $database->image(), $engine);
    }
}
