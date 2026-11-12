<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\ClusterFailover;
use App\Engines\Requests\ClusterMembership;
use App\Engines\Requests\ClusterRejoin;
use App\Engines\Responses\ClusterPosition;
use App\Engines\Responses\ClusterQuorum;
use App\Engines\Responses\Done;
use App\Engines\Responses\Operation;
use App\Models\DatabaseNode;

final readonly class Cluster
{
    public function __construct(
        private SentinelScripts $scripts,
        private Configuration $configuration,
    ) {}

    public function enable(DatabaseNode $node, ClusterEnable $request): Operation
    {
        $saved = $this->configuration->savedRequestOf($node);

        return $this->scripts->start($node, $request->key, 'cluster/enable', ['SELF' => $saved->ip, 'REQUIRED' => (string) SentinelFiles::requiredReplicasFor(count($saved->members))]);
    }

    public function disable(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'cluster/disable');

        return new Done;
    }

    public function failover(DatabaseNode $node, ClusterFailover $request): Done
    {
        $this->scripts->run($node, 'cluster/failover', ['TARGET' => $request->target, 'SELF' => $this->configuration->savedRequestOf($node)->ip], timeout: 900);

        return new Done;
    }

    public function forceQuorum(DatabaseNode $node): Done
    {
        $saved = $this->configuration->savedRequestOf($node);
        $this->scripts->run($node, 'cluster/force-quorum', ['SELF' => $saved->ip], timeout: 900);

        return new Done;
    }

    public function membership(DatabaseNode $node, ClusterMembership $request): Done
    {
        $size = count($request->members);
        $this->scripts->run($node, 'cluster/membership', ['QUORUM' => (string) SentinelFiles::majorityOf($size), 'REQUIRED' => (string) SentinelFiles::requiredReplicasFor($size)]);

        return new Done;
    }

    public function position(DatabaseNode $node): ClusterPosition
    {
        return ClusterPosition::fromPayload($this->scripts->run($node, 'cluster/position', timeout: 300)->json());
    }

    public function quorum(DatabaseNode $node): ClusterQuorum
    {
        return ClusterQuorum::fromPayload($this->scripts->run($node, 'cluster/quorum')->json());
    }

    public function rejoin(DatabaseNode $node, ClusterRejoin $request): Operation
    {
        $saved = $this->configuration->savedRequestOf($node);

        return $this->scripts->start($node, $request->key, 'cluster/rejoin', [
            'SELF' => $saved->ip,
            'READ_ONLY' => $request->readOnly ? 'true' : 'false',
            'PERSISTENT' => SentinelFiles::persistent($saved->settings) ? 'true' : 'false',
        ]);
    }
}
