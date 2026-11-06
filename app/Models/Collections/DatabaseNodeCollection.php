<?php

declare(strict_types=1);

namespace App\Models\Collections;

use App\Enums\Databases\DatabaseStatus;
use App\Enums\Databases\NodeRole;
use App\Models\DatabaseNode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * @extends Collection<int, DatabaseNode>
 */
final class DatabaseNodeCollection extends Collection
{
    public function members(): self
    {
        return $this->filter(fn (DatabaseNode $node): bool => $node->isMember())->values();
    }

    public function readOnly(): self
    {
        return $this->filter(fn (DatabaseNode $node): bool => $node->isReadOnly())->values();
    }

    public function primary(): ?DatabaseNode
    {
        return $this->first(fn (DatabaseNode $node): bool => $node->isPrimary());
    }

    public function replica(): ?DatabaseNode
    {
        return $this->first(fn (DatabaseNode $node): bool => $node->isReplica());
    }

    public function soloPrimary(): ?DatabaseNode
    {
        return $this->primaryCount() === 1 ? $this->primary() : null;
    }

    public function primaryCount(): int
    {
        return $this->filter(fn (DatabaseNode $node): bool => $node->isPrimary())->count();
    }

    public function entry(): ?DatabaseNode
    {
        return $this->primary() ?? $this->first(fn (DatabaseNode $node): bool => $node->role->serves()) ?? $this->first();
    }

    public function standbyBesides(DatabaseNode $entry): ?DatabaseNode
    {
        $others = $this->reject(fn (DatabaseNode $node): bool => $node->is($entry));

        return $others->first(fn (DatabaseNode $node): bool => $node->isReplica()) ?? $others->first();
    }

    public function standbysBesides(DatabaseNode $primary): self
    {
        return $this->reject(fn (DatabaseNode $node): bool => $node->isReadOnly() || $node->is($primary))->values();
    }

    public function readers(DatabaseNode $primary): self
    {
        $replicas = $this->filter(fn (DatabaseNode $node): bool => $node->isReplica())->values();

        return $replicas->isEmpty() ? new self([$primary]) : $replicas;
    }

    /**
     * @return BaseCollection<int, string>
     */
    public function namesWithRole(NodeRole $role): BaseCollection
    {
        return $this->toBase()->filter(fn (DatabaseNode $node): bool => $node->role === $role)->map(fn (DatabaseNode $node): string => $node->server->name)->values();
    }

    public function problems(): string
    {
        $unreachable = $this->namesWithRole(NodeRole::Unknown);
        $offline = $this->namesWithRole(NodeRole::Offline);
        $primaries = $this->primaryCount();

        return collect([
            $unreachable->isNotEmpty() ? 'Unreachable: '.$unreachable->implode(', ').'.' : null,
            $offline->isNotEmpty() ? 'Not serving: '.$offline->implode(', ').'.' : null,
            $primaries === 0 ? 'No primary is serving writes.' : null,
            $primaries > 1 ? "{$primaries} nodes claim to be primary." : null,
        ])->filter()->implode(' ');
    }

    public function healthStatus(): DatabaseStatus
    {
        if ($this->doesntContain(fn (DatabaseNode $node): bool => $node->role->serves())) {
            return DatabaseStatus::Offline;
        }

        $healthy = $this->primaryCount() === 1 && $this->every(fn (DatabaseNode $node): bool => $node->role->serves());

        return $healthy ? DatabaseStatus::Ready : DatabaseStatus::Degraded;
    }
}
