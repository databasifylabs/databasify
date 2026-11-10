<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\HostDeploy as HostDeployReply;

/**
 * @implements EngineRequest<HostDeployReply>
 */
final readonly class HostDeploy implements EngineRequest
{
    public function __construct(
        public string $image,
        public string $databaseId,
        public string $nodeId,
    ) {}

    public function command(): string
    {
        return 'host deploy';
    }

    public function toPayload(): array
    {
        return ['image' => $this->image, 'databaseId' => $this->databaseId, 'nodeId' => $this->nodeId];
    }

    public function reply(): string
    {
        return HostDeployReply::class;
    }
}
