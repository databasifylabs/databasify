<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Drivers\MySql\MySqlFiles;
use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\ClusterFailover;
use App\Engines\Requests\ClusterForceQuorum;
use App\Engines\Requests\ClusterMembership;
use App\Engines\Requests\ClusterRejoin;
use App\Engines\Requests\MemberForget;
use App\Engines\Requests\NodeConfigure;
use App\Engines\Responses\ClusterPosition;
use App\Engines\Responses\ClusterQuorum;
use App\Engines\Responses\Done;
use App\Engines\Responses\Operation;
use App\Exceptions\Engines\EngineBusy;
use App\Models\DatabaseNode;

final readonly class Cluster
{
    private const int WAITING_TIMEOUT = 1900;

    public function __construct(
        private FamilyScripts $scripts,
        private Configuration $configuration,
    ) {}

    public function enable(DatabaseNode $node, ClusterEnable $request): Operation
    {
        $engine = FamilyScripts::engineOf($node);
        $saved = $this->configuration->savedRequestOf($node);
        $reserved = ['RESERVED_DATABASES' => json_encode($engine->reservedDatabases, JSON_THROW_ON_ERROR)];
        $env = $engine->id === 'mysql' ? ['GROUP_NAME' => MySqlFiles::groupNameOf($saved->databaseId)] : $this->filesEnvOf($node, $saved);

        return $this->scripts->start($node, $request->key, '{engine}/cluster/enable', [...$reserved, ...$env]);
    }

    public function disable(DatabaseNode $node): Done
    {
        $env = [];

        if (FamilyScripts::engineOf($node)->id === 'mariadb') {
            $saved = $this->configuration->savedRequestOf($node);
            $env = $this->filesEnvOf($node, new NodeConfigure(false, $saved->readOnly, $saved->ip, $saved->databaseId, $saved->members, $saved->settings, $saved->secrets, $saved->archive, $saved->primary));
        }

        $this->scripts->run($node, '{engine}/cluster/disable', $env, timeout: self::WAITING_TIMEOUT);

        return new Done;
    }

    public function failover(DatabaseNode $node, ClusterFailover $request): Done
    {
        $this->scripts->run($node, '{engine}/cluster/failover', ['TARGET' => $request->target], timeout: 180);

        return new Done;
    }

    public function forceQuorum(DatabaseNode $node, ClusterForceQuorum $request): Done
    {
        $mysql = FamilyScripts::engineOf($node)->id === 'mysql';

        if (! $request->othersDown) {
            throw new EngineBusy('busy', $mysql
                ? 'forcing the group while other members may be alive would split it; confirm the others are down'
                : 'forcing the cluster while other nodes may be alive would split it; confirm the others are down');
        }

        $env = $mysql ? [] : $this->filesEnvOf($node, $this->configuration->savedRequestOf($node));
        $this->scripts->run($node, '{engine}/cluster/force-quorum', $env, timeout: self::WAITING_TIMEOUT);

        return new Done;
    }

    public function membership(DatabaseNode $node, ClusterMembership $request): Done
    {
        if (FamilyScripts::engineOf($node)->id === 'mysql') {
            $this->scripts->run($node, 'mysql/cluster/membership', ['SEEDS' => MySqlFiles::seedsOf($request->members)]);

            return new Done;
        }

        $saved = $this->configuration->savedRequestOf($node);
        $this->scripts->run($node, 'mariadb/cluster/membership', $this->filesEnvOf($node, new NodeConfigure($saved->clustered, $saved->readOnly, $saved->ip, $saved->databaseId, $request->members, $saved->settings, $saved->secrets, $saved->archive, $saved->primary)));

        return new Done;
    }

    public function position(DatabaseNode $node): ClusterPosition
    {
        if (FamilyScripts::engineOf($node)->id === 'mariadb') {
            return ClusterPosition::fromPayload($this->scripts->run($node, 'mariadb/cluster/position', timeout: 300)->json());
        }

        $executed = $this->scripts->run($node, 'mysql/cluster/position')->json()->nullableString('executed');

        return new ClusterPosition($executed === null ? -1 : $this->transactionsIn($executed));
    }

    public function quorum(DatabaseNode $node): ClusterQuorum
    {
        return ClusterQuorum::fromPayload($this->scripts->run($node, '{engine}/cluster/quorum')->json());
    }

    public function rejoin(DatabaseNode $node, ClusterRejoin $request): Operation
    {
        return $this->scripts->start($node, $request->key, '{engine}/cluster/rejoin', ['READ_ONLY' => $request->readOnly ? 'true' : 'false']);
    }

    public function forget(DatabaseNode $node, MemberForget $request): Done
    {
        if (FamilyScripts::engineOf($node)->id === 'mariadb') {
            return new Done;
        }

        $this->scripts->run($node, 'mysql/members/forget', ['SEED' => "{$request->ip}:".MySqlFiles::GROUP_PORT]);

        return new Done;
    }

    /**
     * @return array{REQUEST_JSON: string, FILES: string, MANIFEST: string, MANIFEST_NEW_CLUSTER: string}
     */
    private function filesEnvOf(DatabaseNode $node, NodeConfigure $request): array
    {
        return $this->configuration->filesEnvOf($node, $request, $this->configuration->tunedOf($node, $request));
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
                $total += ($last === null ? (int) $first : (int) $last) - (int) $first + 1;
            }
        }

        return $total;
    }
}
