<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\Engines\Data\Operation;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\EngineTasks;
use RuntimeException;

final readonly class RecoverCluster
{
    public function __construct(
        private EngineTasks $tasks,
        private RefreshDatabaseStatus $refresh,
    ) {}

    /**
     * @param  list<string>  $nodeIds
     */
    public function handle(Database $database, array $nodeIds, bool $othersDown): void
    {
        $nodes = DatabaseNode::query()->with('server')->findMany($nodeIds)->sortBy('position')->values()->toBase();
        $nodes->each(fn (DatabaseNode $node) => $node->driver()->nodes()->wake(all: false));
        $positions = $nodes->map(fn (DatabaseNode $node): ?int => $node->driver()->cluster()->position());

        if ($positions->contains(null)) {
            throw new RuntimeException('A member could not say how far its data got, so Databasify will not pick a new primary. Check the members and recover again.');
        }

        $survivor = $nodes->sortByDesc(fn (DatabaseNode $node, int $index): ?int => $positions[$index])->firstOrFail();

        $survivor->driver()->cluster()->forceQuorum($othersDown);
        $nodes->reject(fn (DatabaseNode $node): bool => $node->is($survivor))
            ->each(fn (DatabaseNode $node) => $this->tasks->await($node, 'rejoining the cluster', fn (string $key): Operation => $node->driver()->cluster()->rejoin($key, readOnly: false)));

        $this->refresh->handle($database);
        $database->markReady();
    }
}
