<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\DTOs\Databases\QuorumCount;
use App\Engines\Contracts\Cluster;
use App\Engines\Data\Member;
use App\Engines\Data\Operation;
use App\Exceptions\Engines\EngineBusy;

final readonly class MongoDbCluster implements Cluster
{
    private const int WAITING_TIMEOUT = 900;

    public function __construct(
        private MongoDbDriver $driver,
        private MongoDbConfiguration $configuration,
        private MongoDbNodes $lifecycle,
    ) {}

    public function enable(string $key): Operation
    {
        return $this->driver->task($key, 'engines.mongodb.cluster.enable', env: $this->setEnvOf());
    }

    public function disable(): void
    {
        $this->driver->run('engines.mongodb.cluster.disable', timeout: self::WAITING_TIMEOUT, env: $this->setEnvOf());
    }

    public function failover(string $targetIp): void
    {
        $this->driver->run('engines.mongodb.cluster.failover', timeout: self::WAITING_TIMEOUT, env: [...$this->setEnvOf(), 'TARGET' => $targetIp, 'TARGET_HOST' => MongoDbFiles::hostOf($targetIp)]);
    }

    public function forceQuorum(bool $othersDown): void
    {
        if (! $othersDown) {
            throw new EngineBusy('busy', 'forcing the replica set while other members may be alive would roll back writes; confirm the others are down');
        }

        $this->driver->run('engines.mongodb.cluster.force-quorum');
    }

    public function membership(array $members): void
    {
        $nonVoting = array_filter($members, fn (Member $member): bool => ! $member->voting);
        $this->driver->run('engines.mongodb.cluster.membership', timeout: self::WAITING_TIMEOUT, env: [
            ...$this->setEnvOf(),
            'WANTED' => $this->hostsOf($members),
            'NON_VOTING' => $this->hostsOf($nonVoting),
        ]);
    }

    public function position(): ?int
    {
        return $this->driver->read('engines.mongodb.cluster.position')->nullableInt('position');
    }

    public function quorum(): QuorumCount
    {
        $reply = $this->driver->read('engines.mongodb.cluster.quorum');

        return new QuorumCount($reply->int('total'), $reply->int('online'));
    }

    public function rejoin(string $key, bool $readOnly): Operation
    {
        return $this->driver->task($key, 'engines.mongodb.cluster.rejoin', env: $this->setEnvOf());
    }

    public function forget(string $ip): void
    {
        $this->driver->run('engines.mongodb.members.forget', env: [...$this->setEnvOf(), 'GONE' => MongoDbFiles::hostOf($ip)]);
    }

    /**
     * @return array{HOSTS: string, SELF: string, SEED: string, READ_ONLY: string}
     */
    private function setEnvOf(): array
    {
        $saved = $this->configuration->savedRequestOf();

        return [
            'HOSTS' => $this->hostsOf($saved->members),
            'SELF' => MongoDbFiles::hostOf($saved->ip),
            'SEED' => $this->lifecycle->isSeed($saved) ? 'true' : 'false',
            'READ_ONLY' => $saved->readOnly ? 'true' : 'false',
        ];
    }

    /**
     * @param  array<array-key, Member>  $members
     */
    private function hostsOf(array $members): string
    {
        return implode(',', array_map(fn (Member $member): string => MongoDbFiles::hostOf($member->ip), $members));
    }
}
