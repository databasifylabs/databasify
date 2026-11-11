<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Engines\Requests\ClusterFailover;
use App\Engines\Requests\ClusterRejoin;
use App\Engines\Requests\NodeReady;
use App\Engines\Requests\NodeRestart;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Closure;

final readonly class RollingSteps
{
    public function __construct(private FlowSteps $steps) {}

    public static function contextFor(Database $database, ?DatabaseNode $only = null): FlowContext
    {
        $nodes = $database->nodesWithServers();
        $primary = $database->primaryOrSeed();
        $standbys = $nodes->standbysBesides($primary);
        $order = [...$nodes->readOnly()->modelKeys(), ...$standbys->modelKeys(), $primary->id];

        return new FlowContext(
            nodeIds: array_values(array_filter($order, fn (mixed $id): bool => is_string($id) && ($only === null || $id === $only->id))),
            targetNodeId: $primary->id,
            failoverTo: $standbys->first()?->id,
        );
    }

    /**
     * @param  Closure(DatabaseNode): list<Step>  $stepsFor
     * @return list<Step>
     */
    public function over(DatabaseFlow $flow, Closure $stepsFor, bool $failover = true): array
    {
        $context = $flow->context;
        $nodes = DatabaseNode::query()->with('server', 'database')->findMany($context->nodeIds);
        $standby = ! $failover || $context->failoverTo === null ? null : DatabaseNode::query()->with('server')->find($context->failoverTo);
        $steps = [];

        foreach ($context->nodeIds as $id) {
            $node = $nodes->find($id);

            if ($node === null) {
                continue;
            }

            if ($id === $context->targetNodeId && $standby !== null) {
                $steps[] = Step::prepare("hand the primary to {$standby->server->name}", $this->steps->send($node, new ClusterFailover($standby->privateIp()), 'handing the primary over'));
            }

            array_push($steps, ...$stepsFor($node));
        }

        return $steps;
    }

    /**
     * @return list<Step>
     */
    public function restart(DatabaseFlow $flow): array
    {
        return $this->over($flow, fn (DatabaseNode $node): array => [
            Step::prepare("restart {$node->server->name}", $this->steps->send($node, new NodeRestart, 'restarting the engine')),
            ...$this->backOnline($flow, $node),
        ]);
    }

    /**
     * @return list<Step>
     */
    public function backOnline(DatabaseFlow $flow, DatabaseNode $node): array
    {
        $expectPrimary = $node->id === $flow->context->targetNodeId && $flow->context->failoverTo === null;
        $ready = Step::prepare("wait for {$node->server->name}", $this->steps->long($node, fn (string $key): NodeReady => new NodeReady($key, $expectPrimary), 'waiting for the node'));

        if (! $node->database->isClustered()) {
            return [$ready];
        }

        return [$ready, Step::prepare("rejoin {$node->server->name}", $this->steps->long($node, fn (string $key): ClusterRejoin => new ClusterRejoin($key, $node->isReadOnly()), 'rejoining the cluster'))];
    }
}
