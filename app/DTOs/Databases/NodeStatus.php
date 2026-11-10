<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Engines\Responses\NodeStatus as EngineNodeStatus;
use App\Enums\Databases\NodeRole;

final readonly class NodeStatus
{
    public function __construct(
        public NodeRole $role,
        public ?string $version,
    ) {}

    public static function fromEngine(EngineNodeStatus $status): self
    {
        return new self($status->role === NodeRole::Unknown ? NodeRole::Offline : $status->role, $status->version);
    }

    public static function stopped(): self
    {
        return new self(NodeRole::Offline, null);
    }

    public static function unreachable(): self
    {
        return new self(NodeRole::Unknown, null);
    }

    public function isUnreachable(): bool
    {
        return $this->role === NodeRole::Unknown;
    }
}
