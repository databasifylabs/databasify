<?php

declare(strict_types=1);

namespace App\Actions\Databases\Domains;

use App\DTOs\Databases\DatabaseHostnames;
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
            $loaded->dnsLock()->block(self::WAIT_SECONDS, fn () => $this->syncCurrent($loaded));
        } catch (LockTimeoutException) {
            return;
        }
    }

    private function syncCurrent(Database $loaded): void
    {
        $database = $loaded->fresh();
        $zone = $database?->dnsZone;
        $hostnames = $database?->hostnames();

        if ($database === null || $zone === null || $hostnames === null) {
            return;
        }

        try {
            $this->publish($database, $zone, $hostnames);
            Cache::forget($this->failureKeyOf($database));
        } catch (ProviderRequestFailed $failure) {
            $this->reportOnce($database, $failure);
        }
    }

    private function reportOnce(Database $database, ProviderRequestFailed $failure): void
    {
        if (! Cache::add($this->failureKeyOf($database), true, self::REMIND_SECONDS)) {
            return;
        }

        $database->seed()->server->logFailure('Sync DNS records', "Could not update the DNS records of {$database->name}: {$failure->getMessage()}", $database);
    }

    private function failureKeyOf(Database $database): string
    {
        return "dns-sync-failed:{$database->id}";
    }

    private function publish(Database $database, DnsZone $zone, DatabaseHostnames $hostnames): void
    {
        $nodes = $database->nodesWithServers()->each(fn (DatabaseNode $node) => $node->setRelation('database', $database));
        $members = $nodes->members();
        $primary = $members->soloPrimary();

        if ($primary !== null) {
            $this->pointPair($zone, $hostnames->primary, collect([$primary]));
            $this->pointPair($zone, $hostnames->replica, $members->readers($primary));
        }

        $nodes->readOnly()->each(fn (DatabaseNode $node) => $this->pointNode($zone, $node));
        $records = $database->serviceRecords();

        if ($records !== null) {
            $this->services->handle($records, $nodes);
        }
    }

    private function pointNode(DnsZone $zone, DatabaseNode $node): void
    {
        $hostnames = $node->hostnames();

        if ($hostnames !== null) {
            $this->pointPair($zone, $hostnames, collect([$node]));
        }
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     */
    private function pointPair(DnsZone $zone, HostPair $pair, Collection $nodes): void
    {
        $this->dns->point($zone, $pair->public, $this->addressesOf($nodes, fn (DatabaseNode $node): string => $node->server->address()));
        $this->dns->point($zone, $pair->private, $this->addressesOf($nodes, fn (DatabaseNode $node): string => $node->privateIp()));
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     * @param  callable(DatabaseNode): string  $address
     * @return list<string>
     */
    private function addressesOf(Collection $nodes, callable $address): array
    {
        return array_values($nodes->map($address)->unique()->sort()->all());
    }
}
