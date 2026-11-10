<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Data\DefaultAccount;
use App\Engines\Data\Secrets;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class NodeBootstrap implements EngineRequest
{
    public function __construct(
        public Secrets $secrets,
        public ?DefaultAccount $account,
    ) {}

    public function command(): string
    {
        return 'node bootstrap';
    }

    public function toPayload(): array
    {
        return ['secrets' => $this->secrets->toPayload(), 'account' => $this->account?->toPayload()];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
