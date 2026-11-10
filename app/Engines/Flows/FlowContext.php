<?php

declare(strict_types=1);

namespace App\Engines\Flows;

final readonly class FlowContext
{
    /**
     * @param  list<string>  $nodeIds
     * @param  array<string, string>  $bootIds
     */
    public function __construct(
        public array $nodeIds,
        public ?string $targetNodeId = null,
        public ?string $failoverTo = null,
        public bool $force = false,
        public array $bootIds = [],
        public bool $restart = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $nodeIds = $values['node_ids'] ?? [];
        $bootIds = $values['boot_ids'] ?? [];
        $target = $values['target_node_id'] ?? null;
        $failoverTo = $values['failover_to'] ?? null;

        return new self(
            is_array($nodeIds) ? array_values(array_filter($nodeIds, is_string(...))) : [],
            is_string($target) ? $target : null,
            is_string($failoverTo) ? $failoverTo : null,
            ($values['force'] ?? false) === true,
            is_array($bootIds) ? array_filter($bootIds, fn (mixed $id, int|string $node): bool => is_string($id) && is_string($node), ARRAY_FILTER_USE_BOTH) : [],
            ($values['restart'] ?? false) === true,
        );
    }

    public function needingRestart(bool $restart): self
    {
        return clone ($this, ['restart' => $restart]);
    }

    public function withBootId(string $nodeId, string $bootId): self
    {
        return clone ($this, ['bootIds' => [...$this->bootIds, $nodeId => $bootId]]);
    }

    /**
     * @return array{node_ids: list<string>, target_node_id: ?string, failover_to: ?string, force: bool, boot_ids: array<string, string>, restart: bool}
     */
    public function toArray(): array
    {
        return ['node_ids' => $this->nodeIds, 'target_node_id' => $this->targetNodeId, 'failover_to' => $this->failoverTo, 'force' => $this->force, 'boot_ids' => $this->bootIds, 'restart' => $this->restart];
    }
}
