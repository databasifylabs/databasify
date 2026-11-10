<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\Actions\Databases\Lifecycle\RedeployDatabase;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\Database;
use App\Models\DnsZone;
use Illuminate\Validation\ValidationException;

final readonly class AssignDatabaseZone
{
    private const int DNS_WAIT_SECONDS = 60;

    public function __construct(
        private ClearDatabaseDns $clear,
        private RedeployDatabase $redeploy,
        private EnsureHostnamesFree $free,
    ) {}

    public function handle(Database $database, ?DnsZone $zone): void
    {
        $database->ensureIdle();
        $this->ensureZoneChangesFor($database, $zone);

        if ($zone !== null) {
            $this->free->handle($zone, $database->hostnamesClaimedUnder($zone), 'dns_zone_id');
        }

        $database->dnsLock()->block(self::DNS_WAIT_SECONDS, function () use ($database, $zone): void {
            $this->clearRecordsOf($database);
            $database->moveToZone($zone);
        });

        $this->redeploy->handle($database, $this->titleFor($database, $zone));
    }

    private function clearRecordsOf(Database $database): void
    {
        try {
            $this->clear->handle($database);
        } catch (ProviderRequestFailed $failure) {
            throw ValidationException::withMessages(['dns_zone_id' => "Could not remove the DNS records of {$database->name}, try again: {$failure->getMessage()}"]);
        }
    }

    private function ensureZoneChangesFor(Database $database, ?DnsZone $zone): void
    {
        if ($zone?->id !== $database->dns_zone_id) {
            return;
        }

        $message = $zone === null ? "{$database->name} doesn't use a domain." : "{$database->name} already uses {$zone->name}.";

        throw ValidationException::withMessages(['dns_zone_id' => $message]);
    }

    private function titleFor(Database $database, ?DnsZone $zone): string
    {
        return $zone === null ? 'Stop using a domain' : 'Serve on '.$database->hostnamesUnder($zone)->primary->public;
    }
}
