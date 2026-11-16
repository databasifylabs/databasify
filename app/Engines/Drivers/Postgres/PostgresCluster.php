<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\DTOs\Databases\QuorumCount;
use App\Engines\Contracts\Cluster;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Operation;

final readonly class PostgresCluster implements Cluster
{
    private const int WAITING_TIMEOUT = 900;

    public function __construct(
        private PostgresDriver $driver,
        private PostgresConfiguration $configuration,
    ) {}

    public function enable(string $key): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->driver->task($key, 'engines.postgres.cluster.enable', ['saved' => $saved, 'etcdEndpoints' => PostgresFiles::etcdEndpointsOf($saved)]);
    }

    public function disable(): void
    {
        $this->driver->run('engines.postgres.cluster.disable-stop');
        $saved = $this->configuration->savedRequestOf();
        $this->configuration->configure(new NodeConfig(false, false, $saved->ip, $saved->databaseId, $saved->members, $saved->settings, $saved->secrets, $saved->archive, $saved->primary));
        $this->driver->run('engines.postgres.cluster.disable-finish', timeout: 420);
    }

    public function failover(string $targetIp): void
    {
        $this->driver->run('engines.postgres.cluster.failover', ['target' => $targetIp], timeout: 480);
    }

    public function forceQuorum(bool $othersDown): void
    {
        $this->driver->run('engines.postgres.cluster.force-quorum', ['saved' => $this->configuration->savedRequestOf(), 'othersDown' => $othersDown], timeout: self::WAITING_TIMEOUT);
    }

    public function membership(array $members): void
    {
        if (! $this->configuration->savedRequestOf()->clustered) {
            return;
        }

        $this->driver->run('engines.postgres.cluster.membership', ['hosts' => PostgresFiles::etcdHostsOf($members)]);
    }

    public function rejoin(string $key, bool $readOnly): Operation
    {
        return $this->driver->task($key, 'engines.postgres.cluster.rejoin', ['units' => PostgresManifest::unitsOf($this->configuration->savedRequestOf())]);
    }

    public function forget(string $ip): void
    {
        $this->driver->run('engines.postgres.members.forget', ['ip' => $ip]);
    }

    public function position(): ?int
    {
        return $this->driver->read('engines.postgres.cluster.position', timeout: 180)->nullableInt('position');
    }

    public function quorum(): QuorumCount
    {
        $voters = collect($this->configuration->savedRequestOf()->members)
            ->filter(fn (Member $member): bool => $member->voting)
            ->map(fn (Member $member): string => $member->ip)
            ->values()
            ->all();

        return new QuorumCount(count($voters), $this->driver->read('engines.postgres.cluster.quorum', ['voters' => $voters], timeout: 300)->int('online'));
    }
}
