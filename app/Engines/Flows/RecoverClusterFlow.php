<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Contracts\Engines\Flow;
use App\Engines\EngineClient;
use App\Engines\Requests\ClusterForceQuorum;
use App\Engines\Requests\ClusterPosition;
use App\Engines\Requests\ClusterRejoin;
use App\Engines\Requests\NodeWake;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Illuminate\Support\Collection;

final readonly class RecoverClusterFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private EngineClient $engine,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return 'Recover from a lost primary';
    }

    public function steps(DatabaseFlow $flow): array
    {
        $nodes = $this->nodesOf($flow);
        $survivor = $nodes->firstWhere('id', $flow->context->targetNodeId);

        return [
            ...$nodes->map(fn (DatabaseNode $node): Step => Step::prepare("wake {$node->server->name}", $this->steps->send($node, new NodeWake(all: false), 'preparing it for recovery')))->all(),
            Step::prepare('pick the most advanced member', $this->steps->quick(fn (DatabaseFlow $flow) => $flow->update(['context' => $flow->context->survivedBy($this->mostAdvancedOf($nodes)->id)]))),
            ...($survivor === null ? [] : [
                Step::prepare("make {$survivor->server->name} the primary", $this->steps->send($survivor, new ClusterForceQuorum($flow->context->force), 'taking over without a majority')),
                ...$nodes->reject(fn (DatabaseNode $node): bool => $node->is($survivor))
                    ->map(fn (DatabaseNode $node): Step => Step::prepare("rejoin {$node->server->name}", $this->steps->long($node, fn (string $key): ClusterRejoin => new ClusterRejoin($key, readOnly: false))))
                    ->all(),
            ]),
            Step::finish('refresh the status', $this->steps->quick(fn () => $this->refresh->handle($flow->database))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     */
    private function mostAdvancedOf(Collection $nodes): DatabaseNode
    {
        $positions = $nodes->map(fn (DatabaseNode $node): ?int => $this->engine->send($node, new ClusterPosition, "{$node->server->name}: reading how far its data got")->position);

        if ($positions->contains(null)) {
            return $nodes->firstOrFail();
        }

        return $nodes->sortByDesc(fn (DatabaseNode $node, int $index): ?int => $positions[$index])->firstOrFail();
    }

    /**
     * @return Collection<int, DatabaseNode>
     */
    private function nodesOf(DatabaseFlow $flow): Collection
    {
        return DatabaseNode::query()->with('server')->findMany($flow->context->nodeIds)->sortBy('position')->values()->toBase();
    }
}
