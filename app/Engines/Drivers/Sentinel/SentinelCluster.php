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
        private SentinelConfiguration $configuration,
    ) {}

    public function enable(string $key): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->driver->task($key, 'engines.sentinel.cluster.enable', [
            'self' => $saved->ip,
            'required' => SentinelFiles::requiredReplicasFor(count($saved->members)),
        ]);
    }

    public function disable(): void
    {
        $this->driver->run('engines.sentinel.cluster.disable');
    }

    public function failover(string $targetIp): void
    {
        $this->driver->run('engines.sentinel.cluster.failover', ['target' => $targetIp, 'self' => $this->selfIp()], timeout: self::WAITING_TIMEOUT);
    }

    public function forceQuorum(bool $othersDown): void
    {
        $this->driver->run('engines.sentinel.cluster.force-quorum', ['self' => $this->selfIp()], timeout: self::WAITING_TIMEOUT);
    }

    public function membership(array $members): void
    {
        $size = count($members);

        $this->driver->run('engines.sentinel.cluster.membership', [
            'quorum' => SentinelFiles::majorityOf($size),
            'required' => SentinelFiles::requiredReplicasFor($size),
        ]);
    }

    public function rejoin(string $key, bool $readOnly): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->driver->task($key, 'engines.sentinel.cluster.rejoin', [
            'self' => $saved->ip,
            'readOnly' => $readOnly,
            'persistent' => SentinelFiles::persistent($saved->settings),
        ]);
    }

    public function forget(string $ip): void
    {
        throw EngineUnsupported::command('member forget');
    }

    public function position(): ?int
    {
        return $this->driver->read('engines.sentinel.cluster.position', timeout: 300)->nullableInt('position');
    }

    public function quorum(): QuorumCount
    {
        $reply = $this->driver->read('engines.sentinel.cluster.quorum');
        $master = new SentinelReply($reply->string('master'));

        if ($master->isEmpty()) {
            return new QuorumCount(0, 0);
        }

        $healthy = collect((new SentinelReply($reply->string('sentinels')))->valuesOf('flags'))
            ->reject(fn (string $flags): bool => preg_match('/s_down|o_down|disconnected/', $flags) === 1)
            ->count();

        return new QuorumCount((int) $master->valueOf('num-other-sentinels') + 1, $reply->bool('serving') ? $healthy + 1 : 0);
    }

    private function selfIp(): string
    {
        return $this->configuration->savedRequestOf()->ip;
    }
}
