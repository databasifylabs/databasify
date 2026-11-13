<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Enums\Databases\NodeRole;

final readonly class NodeStatus
{
    public function __construct(
        public NodeRole $role,
        public ?string $version,
    ) {}

    public static function reported(NodeRole $role, ?string $version): self
    {
        return new self($role === NodeRole::Unknown ? NodeRole::Offline : $role, $version);
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
