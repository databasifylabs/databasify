<?php

declare(strict_types=1);

namespace App\Actions\Databases\Certificates;

use App\Actions\Dns\EnsureZoneCertificate;
use App\Models\Database;
use App\Support\Ssh\CertificateOutput;

final readonly class IssueCertificates
{
    public function __construct(private EnsureZoneCertificate $zoneCertificate) {}

    public function handle(Database $database, bool $reuseAuthority = false): void
    {
        $zone = $database->publicCertificateZone();

        if ($zone !== null) {
            $this->zoneCertificate->handle($zone);
        }

        $issued = CertificateOutput::parse($this->generatedCertificatesOf($database, $reuseAuthority));
        $database->mergeCertificates(array_merge($issued, $zone?->servedCertificate() ?? []));
    }

    private function generatedCertificatesOf(Database $database, bool $reuseAuthority): string
    {
        $node = $database->primaryOrSeed();

        return $node->ssh()->exec(
            'ssh.databases.certificates.generate',
            $database->provider()->scriptData()->certificates($database->certificateNames(), $reuseAuthority),
            "{$node->server->name}: issuing certificates",
            secret: true,
        );
    }
}
