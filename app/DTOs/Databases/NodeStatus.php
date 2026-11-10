<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use App\Engines\Responses\NodeStatus as EngineNodeStatus;
use App\Enums\Databases\NodeRole;

final readonly class NodeStatus
{
    public function __construct(
        public NodeRole $role,
        public ?string $version,
    ) {}

    public static function from(DatabaseDriver $engine, string $output): self
    {
        $role = $engine->role($output);

        return new self($role === NodeRole::Unknown ? NodeRole::Offline : $role, self::versionIn($output));
    }

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

    private static function versionIn(string $output): ?string
    {
        return preg_match('/^version:([0-9][0-9A-Za-z.+-]{0,39})\s*$/m', $output, $match) === 1 ? $match[1] : null;
    }
}
