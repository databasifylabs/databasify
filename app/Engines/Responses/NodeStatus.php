<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;
use App\Enums\Databases\NodeRole;

final readonly class NodeStatus implements EngineReply
{
    public function __construct(
        public NodeRole $role,
        public ?string $version,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        return new self(NodeRole::from($payload->string('role')), $payload->nullableString('version'));
    }
}
