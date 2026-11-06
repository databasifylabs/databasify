<?php

declare(strict_types=1);

namespace App\Actions\Databases\Failover;

use App\Actions\Databases\Lifecycle\RefreshDatabaseStatus;
use App\DTOs\Databases\RecoveryPosition;
use App\Models\Database;
use App\Models\DatabaseNode;
use App\Services\Databases\NodeRunner;
use Illuminate\Support\Collection;
use RuntimeException;

final readonly class PromoteSurvivor
{
    public function __construct(
        private RefreshDatabaseStatus $refresh,
        private NodeRunner $nodeRunner,
    ) {}

    public function handle(Database $database, bool $othersAreDown = false): void
    {
        $this->refresh->handle($database);
        $reachable = $database->reachableMembers();
        $stopped = $reachable->reject(fn (DatabaseNode $node): bool => $node->role->serves());

        $stopped->each(fn (DatabaseNode $node) => $database->provider()->nodes()->wake($node));
        $seed = $this->mostAdvancedOf($reachable, $this->positionsOf($database, $reachable));
        $survivor = $seed ?? $reachable->first() ?? throw new RuntimeException('No reachable member left to recover from.');

        $database->provider()->cluster()->forceQuorum($survivor, $othersAreDown, survivorSeeds: $seed !== null);

        $stopped->reject(fn (DatabaseNode $node): bool => $node->is($survivor))
            ->each(fn (DatabaseNode $node) => $database->provider()->cluster()->rejoin($node));
        $this->refresh->handle($database);
    }

    /**
     * @param  Collection<int, DatabaseNode>  $members
     * @return Collection<int, int|null>
     */
    private function positionsOf(Database $database, Collection $members): Collection
    {
        $read = $this->nodeRunner->map(
            $members,
            fn (DatabaseNode $node): RecoveryPosition => new RecoveryPosition($node->id, $database->provider()->cluster()->recoveryPosition($node)),
            RecoveryPosition::class,
        )->keyBy('nodeId');

        return $members->map(fn (DatabaseNode $node): ?int => $this->positionIn($read, $node));
    }

    /**
     * @param  Collection<array-key, RecoveryPosition>  $read
     */
    private function positionIn(Collection $read, DatabaseNode $node): ?int
    {
        $position = $read->get($node->id) ?? throw new RuntimeException("Could not read the last committed position of {$node->server->name}.");

        return $position->position;
    }

    /**
     * @param  Collection<int, DatabaseNode>  $members
     * @param  Collection<int, int|null>  $positions
     */
    private function mostAdvancedOf(Collection $members, Collection $positions): ?DatabaseNode
    {
        if ($positions->contains(null)) {
            return null;
        }

        return $members->sortByDesc(fn (DatabaseNode $node, int $key): ?int => $positions[$key])->first();
    }
}
