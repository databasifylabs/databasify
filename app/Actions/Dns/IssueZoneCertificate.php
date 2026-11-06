<?php

declare(strict_types=1);

namespace App\Actions\Dns;

use App\Contracts\Acme\CertificateAuthority;
use App\DTOs\Acme\DnsChallenge;
use App\Models\DnsZone;
use App\Services\Dns\DnsRecordPublisher;

final readonly class IssueZoneCertificate
{
    public function __construct(private CertificateAuthority $authority, private DnsRecordPublisher $dns) {}

    public function handle(DnsZone $zone): void
    {
        $published = [];

        try {
            $certificate = $this->authority->issue($zone->wildcardName(), function (DnsChallenge $challenge) use ($zone, &$published): void {
                $published[] = $challenge->name;
                $this->dns->addTxt($zone, $challenge->name, $challenge->value);
            });
        } finally {
            collect($published)->unique()->each(fn (string $name) => $this->dns->clearTxt($zone, $name));
        }

        $zone->storeCertificate($certificate);
    }
}
