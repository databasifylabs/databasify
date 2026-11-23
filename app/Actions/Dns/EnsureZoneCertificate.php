<?php

declare(strict_types=1);

namespace App\Actions\Dns;

use App\Actions\Databases\Certificates\QueueCertificateRenewal;
use App\Models\Database;
use App\Models\DnsZone;
use App\Support\Dns\DnsCache;

final readonly class EnsureZoneCertificate
{
    private const int WAIT_SECONDS = 600;

    public function __construct(
        private IssueZoneCertificate $issue,
        private QueueCertificateRenewal $renewal,
        private DnsCache $cache,
    ) {}

    public function handle(DnsZone $zone): void
    {
        if ($zone->hasCurrentCertificate()) {
            return;
        }

        $this->cache->zoneCertificateLock($zone)->block(self::WAIT_SECONDS, function () use ($zone): void {
            $zone->refresh();

            if ($zone->hasCurrentCertificate()) {
                return;
            }

            $this->issue->handle($zone);
            $zone->databases()->get()->each(function (Database $database): void {
                $this->renewal->handle($database);
            });
        });
    }
}
