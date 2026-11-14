<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\DTOs\Databases\QuorumCount;
use App\Engines\Contracts\Cluster;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Operation;
use App\Engines\Drivers\MySql\MySqlFiles;
use App\Exceptions\Engines\EngineBusy;

final readonly class MySqlFamilyCluster implements Cluster
{
    private const int WAITING_TIMEOUT = 1900;

    public function __construct(
        private MySqlFamilyDriver $driver,
        private FamilyScripts $scripts,
        private MySqlFamilyConfiguration $configuration,
    ) {}

    public function enable(string $key): Operation
    {
        $engine = $this->driver->engine();
        $saved = $this->configuration->savedRequestOf();
        $reserved = ['RESERVED_DATABASES' => json_encode($engine->reservedDatabases, JSON_THROW_ON_ERROR)];
        $env = $engine->id === 'mysql' ? ['GROUP_NAME' => MySqlFiles::groupNameOf($saved->databaseId)] : $this->filesEnvOf($saved);

        return $this->scripts->start($this->driver, $key, 'engines.{engine}.cluster.enable', [...$reserved, ...$env]);
    }

    public function disable(): void
    {
        $env = [];

        if ($this->driver->engine()->id === 'mariadb') {
            $saved = $this->configuration->savedRequestOf();
            $env = $this->filesEnvOf(new NodeConfig(false, $saved->readOnly, $saved->ip, $saved->databaseId, $saved->members, $saved->settings, $saved->secrets, $saved->archive, $saved->primary));
        }

        $this->scripts->run($this->driver, 'engines.{engine}.cluster.disable', $env, timeout: self::WAITING_TIMEOUT);
    }

    public function failover(string $targetIp): void
    {
        $this->scripts->run($this->driver, 'engines.{engine}.cluster.failover', ['TARGET' => $targetIp], timeout: 180);
    }

    public function forceQuorum(bool $othersDown): void
    {
        $mysql = $this->driver->engine()->id === 'mysql';

        if (! $othersDown) {
            throw new EngineBusy('busy', $mysql
                ? 'forcing the group while other members may be alive would split it; confirm the others are down'
                : 'forcing the cluster while other nodes may be alive would split it; confirm the others are down');
        }

        $env = $mysql ? [] : $this->filesEnvOf($this->configuration->savedRequestOf());
        $this->scripts->run($this->driver, 'engines.{engine}.cluster.force-quorum', $env, timeout: self::WAITING_TIMEOUT);
    }

    public function membership(array $members): void
    {
        if ($this->driver->engine()->id === 'mysql') {
            $this->scripts->run($this->driver, 'engines.mysql.cluster.membership', ['SEEDS' => MySqlFiles::seedsOf($members)]);

            return;
        }

        $saved = $this->configuration->savedRequestOf();
        $this->scripts->run($this->driver, 'engines.mariadb.cluster.membership', $this->filesEnvOf(new NodeConfig($saved->clustered, $saved->readOnly, $saved->ip, $saved->databaseId, $members, $saved->settings, $saved->secrets, $saved->archive, $saved->primary)));
    }

    public function position(): ?int
    {
        if ($this->driver->engine()->id === 'mariadb') {
            return $this->scripts->read($this->driver, 'engines.mariadb.cluster.position', timeout: 300)->nullableInt('position');
        }

        $executed = $this->scripts->read($this->driver, 'engines.mysql.cluster.position')->nullableString('executed');

        return $executed === null ? -1 : $this->transactionsIn($executed);
    }

    public function quorum(): QuorumCount
    {
        $reply = $this->scripts->read($this->driver, 'engines.{engine}.cluster.quorum');

        return new QuorumCount($reply->int('total'), $reply->int('online'));
    }

    public function rejoin(string $key, bool $readOnly): Operation
    {
        return $this->scripts->start($this->driver, $key, 'engines.{engine}.cluster.rejoin', ['READ_ONLY' => $readOnly ? 'true' : 'false']);
    }

    public function forget(string $ip): void
    {
        if ($this->driver->engine()->id === 'mariadb') {
            return;
        }

        $this->scripts->run($this->driver, 'engines.mysql.members.forget', ['SEED' => "{$ip}:".MySqlFiles::GROUP_PORT]);
    }

    /**
     * @return array{REQUEST_JSON: string, FILES: string, MANIFEST: string, MANIFEST_NEW_CLUSTER: string}
     */
    private function filesEnvOf(NodeConfig $request): array
    {
        return $this->configuration->filesEnvOf($request, $this->configuration->tunedOf($request));
    }

    private function transactionsIn(string $gtidSet): int
    {
        $compact = str_replace(["\n", ' '], '', $gtidSet);

        if ($compact === '') {
            return -1;
        }

        $total = 0;

        foreach (explode(',', $compact) as $set) {
            foreach (array_slice(explode(':', $set), 1) as $interval) {
                [$first, $last] = array_pad(explode('-', $interval, 2), 2, null);
                $total += (int) ($last ?? $first) - (int) $first + 1;
            }
        }

        return $total;
    }
}
