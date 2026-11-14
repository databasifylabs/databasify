<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\DTOs\Databases\QuorumCount;
use App\Engines\Contracts\Cluster;
use App\Engines\Data\Operation;
use App\Exceptions\Engines\EngineUnsupported;

final readonly class SentinelCluster implements Cluster
{
    private const int WAITING_TIMEOUT = 900;

    public function __construct(
        private SentinelDriver $driver,
        private SentinelScripts $scripts,
        private SentinelConfiguration $configuration,
    ) {}

    public function enable(string $key): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->scripts->start($this->driver, $key, 'cluster/enable', [
            'SELF' => $saved->ip,
            'REQUIRED' => (string) SentinelFiles::requiredReplicasFor(count($saved->members)),
        ]);
    }

    public function disable(): void
    {
        $this->scripts->run($this->driver, 'cluster/disable');
    }

    public function failover(string $targetIp): void
    {
        $this->scripts->run($this->driver, 'cluster/failover', ['TARGET' => $targetIp, 'SELF' => $this->selfIp()], self::WAITING_TIMEOUT);
    }

    public function forceQuorum(bool $othersDown): void
    {
        $this->scripts->run($this->driver, 'cluster/force-quorum', ['SELF' => $this->selfIp()], self::WAITING_TIMEOUT);
    }

    public function membership(array $members): void
    {
        $size = count($members);

        $this->scripts->run($this->driver, 'cluster/membership', [
            'QUORUM' => (string) SentinelFiles::majorityOf($size),
            'REQUIRED' => (string) SentinelFiles::requiredReplicasFor($size),
        ]);
    }

    public function rejoin(string $key, bool $readOnly): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->scripts->start($this->driver, $key, 'cluster/rejoin', [
            'SELF' => $saved->ip,
            'READ_ONLY' => $readOnly ? 'true' : 'false',
            'PERSISTENT' => SentinelFiles::persistent($saved->settings) ? 'true' : 'false',
        ]);
    }

    public function forget(string $ip): void
    {
        throw EngineUnsupported::command('member forget');
    }

    public function position(): ?int
    {
        return $this->scripts->read($this->driver, 'cluster/position', timeout: 300)->nullableInt('position');
    }

    public function quorum(): QuorumCount
    {
        $reply = $this->scripts->read($this->driver, 'cluster/quorum');

        return new QuorumCount($reply->int('total'), $reply->int('online'));
    }

    private function selfIp(): string
    {
        return $this->configuration->savedRequestOf()->ip;
    }
}
