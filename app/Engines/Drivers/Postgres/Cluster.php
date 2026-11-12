<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Postgres;

use App\Contracts\Engines\EngineScripts;
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
    private const int WAITING_TIMEOUT = 900;

    public function __construct(
        private EngineScripts $scripts,
        private Accounts $accounts,
        private Configuration $configuration,
    ) {}

    public function disable(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'postgres/cluster/disable-stop', $this->accounts->dataFor($node));
        $request = $this->configuration->savedRequestOf($node) ?? throw new EngineBusy('busy', 'run node configure first');
        $this->configuration->configure($node, new NodeConfigure(false, false, $request->ip, $request->databaseId, $request->members, $request->settings, $request->secrets, $request->archive, $request->primary));
        $this->scripts->run($node, 'postgres/cluster/disable-finish', $this->accounts->dataFor($node), timeout: 420);

        return new Done;
    }

    public function failover(DatabaseNode $node, ClusterFailover $request): Done
    {
        $this->scripts->run($node, 'postgres/cluster/failover', $this->accounts->dataFor($node), timeout: 480, env: ['TARGET' => $request->target]);

        return new Done;
    }

    public function forceQuorum(DatabaseNode $node, ClusterForceQuorum $request): Done
    {
        $this->scripts->run($node, 'postgres/cluster/force-quorum', $this->accounts->dataFor($node), timeout: self::WAITING_TIMEOUT, env: ['OTHERS_DOWN' => $request->othersDown ? 'true' : 'false']);

        return new Done;
    }

    public function membership(DatabaseNode $node, ClusterMembership $request): Done
    {
        $this->scripts->run($node, 'postgres/cluster/membership', $this->accounts->dataFor($node), env: ['HOSTS' => PostgresFiles::etcdHostsOf($request->members)]);

        return new Done;
    }

    public function position(DatabaseNode $node): ClusterPosition
    {
        return ClusterPosition::fromPayload($this->scripts->run($node, 'postgres/cluster/position', $this->accounts->dataFor($node), timeout: 180, env: ['LINE' => $node->database->version_line])->json());
    }

    public function quorum(DatabaseNode $node): ClusterQuorum
    {
        return ClusterQuorum::fromPayload($this->scripts->run($node, 'postgres/cluster/quorum', $this->accounts->dataFor($node), timeout: 300)->json());
    }

    public function rejoin(DatabaseNode $node, ClusterRejoin $request): Operation
    {
        return $this->scripts->start($node, $request->key, 'postgres/cluster/rejoin', $this->accounts->dataFor($node));
    }

    public function forget(DatabaseNode $node, MemberForget $request): Done
    {
        $this->scripts->run($node, 'postgres/members/forget', $this->accounts->dataFor($node), env: ['IP' => $request->ip]);

        return new Done;
    }
}
