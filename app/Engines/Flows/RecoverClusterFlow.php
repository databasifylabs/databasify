<?php

declare(strict_types=1);

namespace App\Engines\Flows;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Contracts\Engines\Flow;
use App\Engines\Data\Operation;
use App\Models\Database;
use App\Models\DatabaseFlow;
use App\Models\DatabaseNode;
use Illuminate\Support\Collection;
use RuntimeException;

final readonly class RecoverClusterFlow implements Flow
{
    public function __construct(
        private FlowSteps $steps,
        private RefreshDatabaseStatus $refresh,
    ) {}

    public function name(Database $database, FlowContext $context): string
    {
        return 'Recover from a lost primary';
    }

    public function steps(DatabaseFlow $flow): array
    {
        $nodes = $this->nodesOf($flow);

        return [
            ...$nodes->map($this->wakeStepFor(...))->all(),
            Step::prepare('pick the most advanced member', $this->steps->quick(fn (DatabaseFlow $flow) => $this->pickSurvivorOf($flow, $nodes))),
            ...$this->takeoverStepsOf($flow, $nodes),
            Step::finish('refresh the status', $this->steps->quick(fn () => $this->refresh->handle($flow->database))),
        ];
    }

    public function cleanup(DatabaseFlow $flow): void
    {
        rescue(fn () => $this->refresh->handle($flow->database));
    }

    private function wakeStepFor(DatabaseNode $node): Step
    {
        return Step::prepare("wake {$node->server->name}", $this->steps->quick(fn () => $node->driver()->nodes()->wake(all: false)));
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     */
    private function pickSurvivorOf(DatabaseFlow $flow, Collection $nodes): void
    {
        $flow->update(['context' => $flow->context->survivedBy($this->mostAdvancedOf($nodes)->id)]);
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     * @return list<Step>
     */
    private function takeoverStepsOf(DatabaseFlow $flow, Collection $nodes): array
    {
        $survivor = $nodes->firstWhere('id', $flow->context->targetNodeId);

        if ($survivor === null) {
            return [];
        }

        return [
            Step::prepare("make {$survivor->server->name} the primary", $this->steps->quick(fn () => $survivor->driver()->cluster()->forceQuorum($flow->context->force))),
            ...$nodes->reject(fn (DatabaseNode $node): bool => $node->is($survivor))->map($this->rejoinStepFor(...))->all(),
        ];
    }

    private function rejoinStepFor(DatabaseNode $node): Step
    {
        return Step::prepare("rejoin {$node->server->name}", $this->steps->long($node, fn (string $key): Operation => $node->driver()->cluster()->rejoin($key, readOnly: false), 'rejoining the cluster'));
    }

    /**
     * @param  Collection<int, DatabaseNode>  $nodes
     */
    private function mostAdvancedOf(Collection $nodes): DatabaseNode
    {
        $positions = $nodes->map(fn (DatabaseNode $node): ?int => $node->driver()->cluster()->position());

        if ($positions->contains(null)) {
            throw new RuntimeException('A member could not say how far its data got, so Databasify will not pick a new primary. Check the members and recover again.');
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
