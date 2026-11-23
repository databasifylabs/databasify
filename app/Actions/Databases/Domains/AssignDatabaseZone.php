<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\Actions\Databases\Lifecycle\RedeployDatabase;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\Database;
use App\Models\DnsZone;
use App\Support\Dns\DnsCache;
use Illuminate\Validation\ValidationException;

final readonly class AssignDatabaseZone
{
    private const int DNS_WAIT_SECONDS = 60;

    public function __construct(
        private ClearDatabaseDns $clear,
        private RedeployDatabase $redeploy,
        private EnsureHostnamesFree $free,
        private DnsCache $cache,
    ) {}

    public function handle(Database $database, ?DnsZone $zone): void
    {
        $database->ensureIdle();

        if ($zone?->id === $database->dns_zone_id) {
            throw ValidationException::withMessages(['dns_zone_id' => $zone === null ? "{$database->name} doesn't use a domain." : "{$database->name} already uses {$zone->name}."]);
        }

        if ($zone !== null) {
            $this->free->handle($zone, $database->hostnamesClaimedUnder($zone), 'dns_zone_id');
        }

        $this->cache->databaseLock($database)->block(self::DNS_WAIT_SECONDS, function () use ($database, $zone): void {
            try {
                $this->clear->handle($database);
            } catch (ProviderRequestFailed $failure) {
                throw ValidationException::withMessages(['dns_zone_id' => "Could not remove the DNS records of {$database->name}, try again: {$failure->getMessage()}"]);
            }

            $database->moveToZone($zone);
        });

        $this->redeploy->handle($database);
    }
}
