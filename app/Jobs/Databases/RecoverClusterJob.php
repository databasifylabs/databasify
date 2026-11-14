<?php

declare(strict_types=1);

namespace App\Jobs\Databases;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Engines\Data\Operation;
use App\Enums\Databases\DatabaseStatus;
use App\Enums\Queues\QueueGroup;
use App\Jobs\Concerns\FinishesOperation;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Collection;
use RuntimeException;

#[Queue(QueueGroup::Databases)]
#[Tries(1)]
#[Timeout(7200)]
#[DeleteWhenMissingModels]
final class RecoverClusterJob implements ShouldQueue
{
    use FinishesOperation, Queueable;

    /**
     * @param  list<string>  $nodeIds
     */
    public function __construct(
        public Database $database,
        public array $nodeIds,
        public bool $othersDown,
    ) {}

    public function handle(EngineTasks $tasks, RefreshDatabaseStatus $refresh): void
    {
        $nodes = DatabaseNode::query()->with('server')->findMany($this->nodeIds)->sortBy('position')->values()->toBase();
        $nodes->each(fn (DatabaseNode $node) => $node->driver()->nodes()->wake(all: false));
        $survivor = $this->mostAdvancedOf($nodes);

        $survivor->driver()->cluster()->forceQuorum($this->othersDown);
        $nodes->reject(fn (DatabaseNode $node): bool => $node->is($survivor))
            ->each(fn (DatabaseNode $node) => $tasks->await($node, 'rejoining the cluster', fn (string $key): Operation => $node->driver()->cluster()->rejoin($key, readOnly: false)));

        $refresh->handle($this->database);
    }

    protected function operatingStatus(): DatabaseStatus
    {
        return DatabaseStatus::Recovering;
    }

    protected function operationName(): string
    {
        return 'Recover from a lost primary';
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
}
