<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\DTOs\Dns\ServiceTarget;
use App\Models\DatabaseNode;
use App\Models\DnsZone;
use Illuminate\Support\Collection;

final readonly class ServiceRecords
{
    private const string MEMBER_INFIX = '-node-';

    public function __construct(
        public DnsZone $zone,
        public string $host,
        public string $name,
        public string $options,
        private string $databaseName,
        private int $port,
    ) {}

    public function memberHostOf(DatabaseNode $node): string
    {
        return $this->zone->hostFor($this->databaseName.self::MEMBER_INFIX.$node->position);
    }

    public function aliasOf(DatabaseNode $node): string
    {
        return ($node->hostnamesUnder($this->zone)->public ?? $this->memberHostOf($node)).':'.$this->port;
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     * @return list<string>
     */
    public function memberHostsOf(Collection $nodes): array
    {
        return array_values($nodes->filter(fn (DatabaseNode $node): bool => $node->isMember())->map($this->memberHostOf(...))->all());
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     * @return list<ServiceTarget>
     */
    public function targetsOf(Collection $nodes): array
    {
        return array_map(fn (string $host): ServiceTarget => new ServiceTarget($host, $this->port), $this->memberHostsOf($nodes));
    }
}
