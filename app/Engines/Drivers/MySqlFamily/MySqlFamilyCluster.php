<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\DTOs\Databases\QuorumCount;
use App\Engines\Contracts\Cluster;
use App\Engines\Data\ConfigFiles;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Data\Operation;
use App\Engines\Drivers\MySql\MySqlFiles;
use App\Engines\Drivers\Support\NodeState;
use App\Exceptions\Engines\EngineBusy;
use App\Exceptions\Engines\EngineError;

final readonly class MySqlFamilyCluster implements Cluster
{
    private const int WAITING_TIMEOUT = 1900;

    public function __construct(
        private MySqlFamilyDriver $driver,
        private FamilyScripts $scripts,
        private MySqlFamilyConfiguration $configuration,
        private NodeState $state,
    ) {}

    public function enable(string $key): Operation
    {
        $engine = $this->driver->engine();
        $saved = $this->configuration->savedRequestOf();
        $data = $engine->id === 'mysql' ? ['groupName' => MySqlFiles::groupNameOf($saved->databaseId), 'allowlist' => MySqlFiles::allowlistOf($saved->members)] : $this->filesDataOf($saved);

        return $this->scripts->start($this->driver, $key, 'engines.{engine}.cluster.enable', ['saved' => $saved, 'reserved' => $engine->reservedDatabases, ...$data], ['REPLICATION_SECRET' => (string) $saved->secrets->replication]);
    }

    public function disable(): void
    {
        $data = [];

        if ($this->driver->engine()->id === 'mariadb') {
            $saved = $this->configuration->savedRequestOf();
            $data = ['saved' => $saved, ...$this->filesDataOf(new NodeConfig(false, $saved->readOnly, $saved->ip, $saved->databaseId, $saved->members, $saved->settings, $saved->secrets, $saved->archive, $saved->primary))];
        }

        $this->scripts->run($this->driver, 'engines.{engine}.cluster.disable', $data, timeout: self::WAITING_TIMEOUT);
    }

    public function failover(string $targetIp): void
    {
        if ($this->driver->engine()->id === 'mysql') {
            $this->scripts->query($this->driver, 'engines.mysql.cluster.failover', ['target' => $targetIp], timeout: 180);

            return;
        }

        $this->scripts->run($this->driver, 'engines.mariadb.cluster.failover', ['saved' => $this->configuration->savedRequestOf(), 'target' => $targetIp], timeout: 180);
    }

    public function forceQuorum(bool $othersDown): void
    {
        $mysql = $this->driver->engine()->id === 'mysql';

        if (! $othersDown) {
            throw new EngineBusy($mysql
                ? 'forcing the group while other members may be alive would split it; confirm the others are down'
                : 'forcing the cluster while other nodes may be alive would split it; confirm the others are down');
        }

        $saved = $this->configuration->savedRequestOf();
        $this->scripts->run($this->driver, 'engines.{engine}.cluster.force-quorum', ['saved' => $saved, ...($mysql ? [] : $this->filesDataOf($saved))], timeout: self::WAITING_TIMEOUT);
    }

    public function membership(array $members): void
    {
        if ($this->driver->engine()->id === 'mysql') {
            $this->scripts->query($this->driver, 'engines.mysql.cluster.membership', MySqlFiles::groupSettingsOf($members));

            return;
        }

        $saved = $this->configuration->savedRequestOf();
        $this->scripts->run($this->driver, 'engines.mariadb.cluster.membership', $this->filesDataOf(new NodeConfig($saved->clustered, $saved->readOnly, $saved->ip, $saved->databaseId, $members, $saved->settings, $saved->secrets, $saved->archive, $saved->primary)));
    }

    public function position(): ?int
    {
        if ($this->driver->engine()->id === 'mariadb') {
            return $this->scripts->read($this->driver, 'engines.mariadb.cluster.position', timeout: 300)->nullableInt('position');
        }

        try {
            $executed = $this->scripts->queryJson($this->driver, 'engines.mysql.cluster.position')->string('executed');
        } catch (EngineError) {
            return -1;
        }

        return $this->transactionsIn($executed);
    }

    public function quorum(): QuorumCount
    {
        if ($this->driver->engine()->id === 'mariadb') {
            $reply = $this->scripts->read($this->driver, 'engines.mariadb.cluster.quorum', ['saved' => $this->configuration->savedRequestOf()]);

            return new QuorumCount($reply->int('total'), $reply->int('online'));
        }

        $saved = $this->state->requestOf($this->driver, MySqlFamilyConfiguration::REQUEST_STATE);
        $voters = collect($saved->members ?? [])->filter(fn (Member $member): bool => $member->voting)->count();

        try {
            $reply = $this->scripts->queryJson($this->driver, 'engines.mysql.cluster.quorum', ['voters' => $voters]);
        } catch (EngineError) {
            return new QuorumCount(0, 0);
        }

        return new QuorumCount($reply->int('total'), $reply->int('online'));
    }

    public function rejoin(string $key, bool $readOnly): Operation
    {
        $saved = $this->configuration->savedRequestOf();

        return $this->scripts->start($this->driver, $key, 'engines.{engine}.cluster.rejoin', ['saved' => $saved, 'readOnly' => $readOnly, 'allowlist' => MySqlFiles::allowlistOf($saved->members)], ['REPLICATION_SECRET' => (string) $saved->secrets->replication]);
    }

    public function forget(string $ip): void
    {
        if ($this->driver->engine()->id === 'mariadb') {
            return;
        }

        $members = array_values(array_filter($this->configuration->savedRequestOf()->members, fn (Member $member): bool => $member->ip !== $ip));
        $this->scripts->query($this->driver, 'engines.mysql.members.forget', MySqlFiles::groupSettingsOf($members));
    }

    /**
     * @return array{files: ConfigFiles, newClusterManifest: ?string, requestJson: string}
     */
    private function filesDataOf(NodeConfig $request): array
    {
        return $this->configuration->filesDataOf($request, $this->configuration->tunedOf($request));
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
