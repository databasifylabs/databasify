<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\DTOs\Databases\QuorumCount;
use App\Engines\Contracts\Cluster;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Operation;
use App\Exceptions\Engines\EngineBusy;

final readonly class MongoDbCluster implements Cluster
{
    private const int WAITING_TIMEOUT = 900;

    private const int SCRIPT_TIMEOUT = 840;

    public function __construct(
        private MongoDbDriver $driver,
        private MongoDbConfiguration $configuration,
        private MongoDbNodes $lifecycle,
    ) {}

    public function enable(string $key): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->driver->task($key, 'engines.mongodb.cluster.enable', [...$this->replicaSetOf($saved), 'seed' => $this->lifecycle->isSeed($saved)]);
    }

    public function disable(): void
    {
        $this->driver->query('engines.mongodb.cluster.disable', $this->waitingData(), timeout: self::WAITING_TIMEOUT);
    }

    public function failover(string $targetIp): void
    {
        $this->driver->query('engines.mongodb.cluster.failover', [
            ...$this->waitingData(),
            'target' => $targetIp,
            'targetHost' => MongoDbFiles::hostOf($targetIp),
        ], timeout: self::WAITING_TIMEOUT);
    }

    public function forceQuorum(bool $othersDown): void
    {
        if (! $othersDown) {
            throw new EngineBusy('forcing the replica set while other members may be alive would roll back writes; confirm the others are down');
        }

        $this->driver->query('engines.mongodb.cluster.force-quorum', ['timeout' => self::SCRIPT_TIMEOUT], timeout: self::WAITING_TIMEOUT);
    }

    public function membership(array $members): void
    {
        $nonVoting = array_filter($members, fn (Member $member): bool => ! $member->voting);
        $this->driver->query('engines.mongodb.cluster.membership', [
            ...$this->waitingData(),
            'wanted' => $this->hostsOf($members),
            'nonVoting' => $this->hostsOf($nonVoting),
        ], timeout: self::WAITING_TIMEOUT);
    }

    public function position(): ?int
    {
        return $this->driver->read('engines.mongodb.cluster.position')->nullableInt('position');
    }

    public function quorum(): QuorumCount
    {
        $reply = $this->driver->queryJson('engines.mongodb.cluster.quorum');

        return new QuorumCount($reply->int('total'), $reply->int('online'));
    }

    public function rejoin(string $key, bool $readOnly): Operation
    {
        return $this->driver->task($key, 'engines.mongodb.cluster.rejoin', $this->replicaSetOf($this->configuration->savedRequestOf()));
    }

    public function forget(string $ip): void
    {
        $this->driver->query('engines.mongodb.members.forget', [...$this->waitingData(), 'gone' => MongoDbFiles::hostOf($ip)], timeout: self::WAITING_TIMEOUT);
    }

    /**
     * @return array{self: string, hosts: list<string>, readOnly: bool, timeout: int}
     */
    private function waitingData(): array
    {
        return [...$this->replicaSetOf($this->configuration->savedRequestOf()), 'timeout' => self::SCRIPT_TIMEOUT];
    }

    /**
     * @return array{self: string, hosts: list<string>, readOnly: bool}
     */
    private function replicaSetOf(NodeConfig $saved): array
    {
        return ['self' => MongoDbFiles::hostOf($saved->ip), 'hosts' => $this->hostsOf($saved->members), 'readOnly' => $saved->readOnly];
    }

    /**
     * @param  array<array-key, Member>  $members
     * @return list<string>
     */
    private function hostsOf(array $members): array
    {
        return array_values(array_map(fn (Member $member): string => MongoDbFiles::hostOf($member->ip), $members));
    }
}
