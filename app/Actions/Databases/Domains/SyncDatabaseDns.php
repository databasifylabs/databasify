<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\DTOs\Dns\HostPair;
use App\Exceptions\Providers\ProviderRequestFailed;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Models\DnsZone;
use App\Services\Dns\DnsRecordPublisher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final readonly class SyncDatabaseDns
{
    private const int REMIND_SECONDS = 86400;

    private const int WAIT_SECONDS = 10;

    public function __construct(
        private DnsRecordPublisher $dns,
        private PublishServiceRecords $services,
    ) {}

    public function handle(Database $loaded): void
    {
        try {
            $loaded->dnsLock()->block(self::WAIT_SECONDS, function () use ($loaded): void {
                $database = $loaded->fresh();
                $zone = $database?->dnsZone;
                $hostnames = $database?->hostnames();

                if ($database === null || $zone === null || $hostnames === null) {
                    return;
                }

                $failureKey = "dns-sync-failed:{$database->id}";

                try {
                    $nodes = $database->nodesWithServers()->each(fn (DatabaseNode $node) => $node->setRelation('database', $database));
                    $members = $nodes->members();
                    $primary = $members->soloPrimary();

                    if ($primary !== null) {
                        $this->pointPair($zone, $hostnames->primary, collect([$primary]));
                        $this->pointPair($zone, $hostnames->replica, $members->readers($primary));
                    }

                    $nodes->readOnly()->each(function (DatabaseNode $node) use ($zone): void {
                        $pair = $node->hostnames();

                        if ($pair !== null) {
                            $this->pointPair($zone, $pair, collect([$node]));
                        }
                    });

                    $records = $database->serviceRecords();

                    if ($records !== null) {
                        $this->services->handle($records, $nodes);
                    }

                    Cache::forget($failureKey);
                } catch (ProviderRequestFailed $failure) {
                    if (Cache::add($failureKey, true, self::REMIND_SECONDS)) {
                        $database->seed()->server->logFailure('Sync DNS records', "Could not update the DNS records of {$database->name}: {$failure->getMessage()}", $database);
                    }
                }
            });
        } catch (LockTimeoutException) {
            return;
        }
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     */
    private function pointPair(DnsZone $zone, HostPair $pair, Collection $nodes): void
    {
        $this->dns->point($zone, $pair->public, array_values($nodes->map(fn (DatabaseNode $node): string => $node->server->address())->unique()->sort()->all()));
        $this->dns->point($zone, $pair->private, array_values($nodes->map(fn (DatabaseNode $node): string => $node->privateIp())->unique()->sort()->all()));
    }
}
