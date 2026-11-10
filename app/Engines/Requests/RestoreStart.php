<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Data\Storage;
use App\Engines\Responses\Operation;
use Carbon\CarbonImmutable;

/**
 * @implements EngineRequest<Operation>
 */
final readonly class RestoreStart implements EngineRequest
{
    public function __construct(
        public string $key,
        public Storage $storage,
        public CarbonImmutable $target,
    ) {}

    public function command(): string
    {
        return 'restore start';
    }

    public function toPayload(): array
    {
        return ['key' => $this->key, 'storage' => $this->storage->toPayload(), 'target' => $this->target->utc()->toIso8601ZuluString()];
    }

    public function reply(): string
    {
        return Operation::class;
    }
}
