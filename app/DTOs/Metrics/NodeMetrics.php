<?php

declare(strict_types=1);

namespace App\DTOs\Metrics;

use App\Models\DatabaseNode;
use App\Support\Ssh\KeyValueOutput;

final readonly class NodeMetrics
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(
        public string $nodeId,
        public string $name,
        public bool $reachable,
        public array $values = [],
    ) {}

    public static function fromOutput(DatabaseNode $node, string $output): self
    {
        return new self($node->id, $node->server->name, true, KeyValueOutput::parse($output));
    }

    public static function unreachable(DatabaseNode $node): self
    {
        return new self($node->id, $node->server->name, false);
    }
}
