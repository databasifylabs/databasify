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

        return $this->scripts->start($node, $request->key, '{engine}/cluster/enable', [
            'RESERVED_DATABASES' => json_encode($engine->reservedDatabases, JSON_THROW_ON_ERROR),
            'GROUP_NAME' => MySqlFiles::groupNameOf($saved->databaseId),
        ]);
    }

    public function disable(DatabaseNode $node): Done
    {
        $this->scripts->run($node, '{engine}/cluster/disable', timeout: self::WAITING_TIMEOUT);

        return new Done;
    }

    public function failover(DatabaseNode $node, ClusterFailover $request): Done
    {
        $this->scripts->run($node, '{engine}/cluster/failover', ['TARGET' => $request->target], timeout: 180);

        return new Done;
    }

    public function forceQuorum(DatabaseNode $node, ClusterForceQuorum $request): Done
    {
        if (! $request->othersDown) {
            throw new EngineBusy('busy', 'forcing the group while other members may be alive would split it; confirm the others are down');
        }

        $this->scripts->run($node, '{engine}/cluster/force-quorum', timeout: self::WAITING_TIMEOUT);

        return new Done;
    }

    public function membership(DatabaseNode $node, ClusterMembership $request): Done
    {
        $this->scripts->run($node, 'mysql/cluster/membership', ['SEEDS' => MySqlFiles::seedsOf($request->members)]);

        return new Done;
    }

    public function position(DatabaseNode $node): ClusterPosition
    {
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
        $this->scripts->run($node, 'mysql/members/forget', ['SEED' => "{$request->ip}:".MySqlFiles::GROUP_PORT]);

        return new Done;
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
