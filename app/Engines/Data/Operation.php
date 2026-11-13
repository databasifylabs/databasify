<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Engines\Payload;
use App\Enums\Databases\OperationState;

final readonly class Operation
{
    public function __construct(
        public string $key,
        public string $kind,
        public OperationState $state,
        public string $phase,
        public ?Progress $progress,
        public ?string $error,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        $progress = $payload->nullableObject('progress');

        return new self(
            key: $payload->string('key'),
            kind: $payload->string('kind'),
            state: OperationState::from($payload->string('state')),
            phase: $payload->string('phase'),
            progress: $progress === null ? null : Progress::fromPayload($progress),
            error: $payload->nullableObject('error')?->string('message'),
        );
    }
}
