<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Actions\Databases\Network\ApplyNodeFirewall;
use App\Actions\Databases\Nodes\BuildNodeRequest;
use App\Actions\Databases\Nodes\DeployNode;
use App\Contracts\Engines\Flow;
use App\Engines\EngineClient;
use App\Engines\Requests\ClusterEnable;
use App\Engines\Requests\ClusterMembership;
use App\Engines\Requests\HostRemove;
use App\Engines\Requests\NodeReady;
use App\Engines\Requests\NodeStart;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Illuminate\Support\Collection;

final readonly class AddNodeFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private EngineClient $engine,
        private DeployNode $deploy,
        private BuildNodeRequest $nodeRequest,
        private ApplyNodeFirewall $firewall,
        private ClearNodeDns $dns,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        $node = $context->targetNodeId === null ? null : $database->nodes()->find($context->targetNodeId);

        return $node === null ? 'Add node' : "Add {$node->kind->label()} node {$node->displayName()}";
    }

    public function steps(DatabaseFlow $flow): array
    {
        $database = $flow->database;
        $joiner = $this->joinerOf($flow);
        $existing = $database->nodesOtherThan($joiner)->load('server')->sortBy('position')->values()->toBase();

        return [
            Step::prepare('deploy the new node', $this->steps->quick(fn () => $this->deploy->handle($database, $joiner))),
            ...$database->nodes()->with('server')->get()->toBase()->map(fn (DatabaseNode $node): Step => $this->firewallStepOn($database, $node))->all(),
            Step::prepare('configure the new node', $this->steps->quick(fn () => $this->configure($database, $joiner))),
            Step::prepare('start the new node', $this->steps->send($joiner, new NodeStart, 'starting the engine')),
            Step::prepare('wait for the new node', $this->steps->long($joiner, fn (string $key): NodeReady => new NodeReady($key, expectPrimary: false), 'waiting for the node')),
            ...$existing->flatMap(fn (DatabaseNode $node): array => $this->membershipStepsOn($database, $node))->all(),
            ...$this->clusterStepsOnLonePeerOf($existing),
            Step::catchUp('copy the data to the new node', $this->steps->long($joiner, fn (string $key): ClusterEnable => new ClusterEnable($key), 'copying the data')),
            Step::finish('refresh the status', $this->steps->quick(function () use ($database): void {
                $database->matchModeToMembers();
                $this->refresh->handle($database);
            })),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        $database = $flow->database;
        $joiner = $database->nodes()->with('server')->find($flow->context->targetNodeId);

        if ($joiner === null) {
            return;
        }

        rescue(fn () => $this->engine->send($joiner, new HostRemove($database->id, erase: true), "{$joiner->server->name}: removing the failed join", EngineClient::REMOVE_TIMEOUT));
        rescue(fn () => $this->dns->handle($joiner));
        $joiner->delete();
        $database->forgetNodeCertificates($joiner);

        $database->nodes()->with('server')->get()->each(function (DatabaseNode $node) use ($database): void {
            rescue(fn () => $this->configure($database, $node));
            rescue(fn () => $this->updateMembersOn($database, $node));
        });
        rescue(fn () => $this->refresh->handle($database));
    }

    private function firewallStepOn(Database $database, DatabaseNode $node): Step
    {
        return Step::prepare("open the firewall on {$node->server->name}", $this->steps->quick(fn () => $this->firewall->handle($database, $node, $database->trustedCidrs())));
    }

    /**
     * @param  Collection<int, DatabaseNode>  $existing
     * @return list<Step>
     */
    private function clusterStepsOnLonePeerOf(Collection $existing): array
    {
        if ($existing->count() !== 1) {
            return [];
        }

        $peer = $existing->sole();

        return [Step::finish("enable the cluster on {$peer->server->name}", $this->steps->long($peer, fn (string $key): ClusterEnable => new ClusterEnable($key), 'enabling the cluster'))];
    }

    /**
     * @return list<Step>
     */
    private function membershipStepsOn(Database $database, DatabaseNode $node): array
    {
        return [
            Step::finish("configure {$node->server->name}", $this->steps->quick(fn () => $this->configure($database, $node))),
            Step::finish("update the members on {$node->server->name}", $this->steps->quick(fn () => $this->updateMembersOn($database, $node))),
        ];
    }

    private function configure(Database $database, DatabaseNode $node): void
    {
        $this->engine->send($node, $this->nodeRequest->handle($database, $node), "{$node->server->name}: configuring the node");
    }

    private function updateMembersOn(Database $database, DatabaseNode $node): void
    {
        $this->engine->send($node, new ClusterMembership($this->nodeRequest->membersOf($database)), "{$node->server->name}: updating the members");
    }

    private function joinerOf(DatabaseFlow $flow): DatabaseNode
    {
        return $flow->database->nodes()->with('server')->findOrFail($flow->context->targetNodeId);
    }
}
