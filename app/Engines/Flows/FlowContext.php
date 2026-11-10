<?php

declare(strict_types=1);

namespace App\Engines\Flows;

final readonly class FlowContext
{
    /**
     * @param  list<string>  $nodeIds
     */
    public function __construct(
        public array $nodeIds,
        public ?string $targetNodeId = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $nodeIds = $values['node_ids'] ?? [];
        $target = $values['target_node_id'] ?? null;

        return new self(
            is_array($nodeIds) ? array_values(array_filter($nodeIds, is_string(...))) : [],
            is_string($target) ? $target : null,
        );
    }

    /**
     * @return array{node_ids: list<string>, target_node_id: ?string}
     */
    public function toArray(): array
    {
        return ['node_ids' => $this->nodeIds, 'target_node_id' => $this->targetNodeId];
    }
}
