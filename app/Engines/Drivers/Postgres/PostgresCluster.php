<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\DTOs\Databases\QuorumCount;
use App\Engines\Contracts\Cluster;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Operation;
use App\Exceptions\Engines\EngineBusy;

final readonly class PostgresCluster implements Cluster
{
    private const int WAITING_TIMEOUT = 900;

    public function __construct(
        private PostgresDriver $driver,
        private PostgresConfiguration $configuration,
    ) {}

    public function enable(string $key): Operation
    {
        return $this->driver->task($key, 'postgres/cluster/enable', $this->driver->data());
    }

    public function disable(): void
    {
        $this->driver->run('postgres/cluster/disable-stop', $this->driver->data());
        $config = $this->configuration->savedConfig() ?? throw new EngineBusy('busy', 'run node configure first');
        $this->configuration->configure(new NodeConfig(false, false, $config->ip, $config->databaseId, $config->members, $config->settings, $config->secrets, $config->archive, $config->primary));
        $this->driver->run('postgres/cluster/disable-finish', $this->driver->data(), timeout: 420);
    }

    public function failover(string $targetIp): void
    {
        $this->driver->run('postgres/cluster/failover', $this->driver->data(), timeout: 480, env: ['TARGET' => $targetIp]);
    }

    public function forceQuorum(bool $othersDown): void
    {
        $this->driver->run('postgres/cluster/force-quorum', $this->driver->data(), timeout: self::WAITING_TIMEOUT, env: ['OTHERS_DOWN' => $othersDown ? 'true' : 'false']);
    }

    public function membership(array $members): void
    {
        $this->driver->run('postgres/cluster/membership', $this->driver->data(), env: ['HOSTS' => PostgresFiles::etcdHostsOf($members)]);
    }

    public function rejoin(string $key, bool $readOnly): Operation
    {
        return $this->driver->task($key, 'postgres/cluster/rejoin', $this->driver->data());
    }

    public function forget(string $ip): void
    {
        $this->driver->run('postgres/members/forget', $this->driver->data(), env: ['IP' => $ip]);
    }

    public function position(): ?int
    {
        return $this->driver->read('postgres/cluster/position', $this->driver->data(), timeout: 180, env: ['LINE' => $this->driver->node->database->version_line])->nullableInt('position');
    }

    public function quorum(): QuorumCount
    {
        $reply = $this->driver->read('postgres/cluster/quorum', $this->driver->data(), timeout: 300);

        return new QuorumCount($reply->int('total'), $reply->int('online'));
    }
}
