<?php

declare(strict_types=1);

namespace App\Actions\Databases\Nodes;

use App\Actions\Databases\Certificates\InstallNodeCertificate;
use App\Actions\Databases\Certificates\IssueNodeCertificates;
use App\Actions\Databases\Extensions\InstallExtensionPackages;
use App\Actions\Dns\EnsureZoneCertificate;
use App\Engines\Runtime\Runtime;
use App\Enums\Engines\Feature;
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
        private InstallExtensionPackages $packages,
    ) {}

    public function handle(Database $database, DatabaseNode $node): void
    {
        $zone = $database->publicCertificateZone();

        if ($zone !== null) {
            rescue(fn () => $this->zoneCertificate->handle($zone));
        }

        $this->certificates->handle($database);
        $this->hostBinary->handle($node);

        $engine = $this->runtime->deploy($node, $database->image()->pinnedReference(), $database->facts()->hostProfile);

        if ($engine !== $database->engine) {
            throw ImageUnavailable::builtForAnotherEngine($database->engine()->label, $database->image(), $engine);
        }

        if ($database->runsSupportedSdk()) {
            $sdk = $this->runtime->engineInfo($node)->sdk;

            if (! SdkRange::allows($sdk)) {
                throw ImageUnavailable::unsupportedSdk($database->engine()->label, $database->image(), $sdk);
            }
        }

        if ($database->offers(Feature::Extensions) && $database->settings->packages !== []) {
            $this->packages->handle($database, $node, $database->settings->packages);
        }

        $this->certificate->handle($database->refresh(), $node);
    }
}
