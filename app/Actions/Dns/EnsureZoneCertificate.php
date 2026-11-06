<?php

declare(strict_types=1);

namespace App\Actions\Dns;

use App\Models\DnsZone;
use Illuminate\Support\Facades\Cache;

final readonly class EnsureZoneCertificate
{
    private const int LOCK_SECONDS = 600;

    public function __construct(private IssueZoneCertificate $issue) {}

    public function handle(DnsZone $zone): void
    {
        if ($zone->hasCurrentCertificate()) {
            return;
        }

        Cache::lock("zone-certificate:{$zone->id}", self::LOCK_SECONDS)->block(self::LOCK_SECONDS, function () use ($zone): void {
            $zone->refresh();

            if ($zone->hasCurrentCertificate()) {
                return;
            }

            $this->issue->handle($zone);
        });
    }
}
