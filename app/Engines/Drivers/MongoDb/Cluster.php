<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MongoDb;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Data\Member;
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
    private const int WAITING_TIMEOUT = 900;

    public function __construct(
        private EngineScripts $scripts,
        private Configuration $configuration,
        private Lifecycle $lifecycle,
    ) {}

    public function enable(DatabaseNode $node, ClusterEnable $request): Operation
    {
        return $this->scripts->start($node, $request->key, 'mongodb/cluster/enable', env: $this->setEnvOf($node));
    }

    public function disable(DatabaseNode $node): Done
    {
        $this->scripts->run($node, 'mongodb/cluster/disable', timeout: self::WAITING_TIMEOUT, env: $this->setEnvOf($node));

        return new Done;
    }

    public function failover(DatabaseNode $node, ClusterFailover $request): Done
    {
        $this->scripts->run($node, 'mongodb/cluster/failover', timeout: self::WAITING_TIMEOUT, env: [...$this->setEnvOf($node), 'TARGET' => $request->target, 'TARGET_HOST' => $this->hostOf($request->target)]);

        return new Done;
    }

    public function forceQuorum(DatabaseNode $node, ClusterForceQuorum $request): Done
    {
        if (! $request->othersDown) {
            throw new EngineBusy('busy', 'forcing the replica set while other members may be alive would roll back writes; confirm the others are down');
        }

        $this->scripts->run($node, 'mongodb/cluster/force-quorum');

        return new Done;
    }

    public function membership(DatabaseNode $node, ClusterMembership $request): Done
    {
        $voting = array_filter($request->members, fn (Member $member): bool => $member->voting);
        $this->scripts->run($node, 'mongodb/cluster/membership', timeout: self::WAITING_TIMEOUT, env: [
            ...$this->setEnvOf($node),
            'WANTED' => implode(',', array_map(fn (Member $member): string => $this->hostOf($member->ip), $request->members)),
            'NON_VOTING' => implode(',', array_map(fn (Member $member): string => $this->hostOf($member->ip), array_diff_key($request->members, $voting))),
        ]);

        return new Done;
    }

    public function position(DatabaseNode $node): ClusterPosition
    {
        return ClusterPosition::fromPayload($this->scripts->run($node, 'mongodb/cluster/position')->json());
    }

    public function quorum(DatabaseNode $node): ClusterQuorum
    {
        return ClusterQuorum::fromPayload($this->scripts->run($node, 'mongodb/cluster/quorum')->json());
    }

    public function rejoin(DatabaseNode $node, ClusterRejoin $request): Operation
    {
        return $this->scripts->start($node, $request->key, 'mongodb/cluster/rejoin', env: $this->setEnvOf($node));
    }

    public function forget(DatabaseNode $node, MemberForget $request): Done
    {
        $this->scripts->run($node, 'mongodb/members/forget', env: [...$this->setEnvOf($node), 'GONE' => $this->hostOf($request->ip)]);

        return new Done;
    }

    /**
     * @return array{HOSTS: string, SELF: string, SEED: string, READ_ONLY: string}
     */
    private function setEnvOf(DatabaseNode $node): array
    {
        $saved = $this->configuration->savedRequestOf($node);

        return [
            'HOSTS' => implode(',', array_map(fn (Member $member): string => $this->hostOf($member->ip), $saved->members)),
            'SELF' => $this->hostOf($saved->ip),
            'SEED' => $this->lifecycle->isSeed($saved) ? 'true' : 'false',
            'READ_ONLY' => $saved->readOnly ? 'true' : 'false',
        ];
    }

    private function hostOf(string $ip): string
    {
        return $ip.':'.MongoDbFiles::CLIENT_PORT;
    }
}
