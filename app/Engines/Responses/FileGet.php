<?php

declare(strict_types=1);

namespace App\Engines\Responses;

use App\Contracts\Engines\EngineReply;
use App\Engines\Payload;

final readonly class FileGet implements EngineReply
{
    public function __construct(
        public ?string $content,
        public bool $exists,
    ) {}

    public static function fromPayload(Payload $payload): static
    {
        if (! $payload->bool('exists')) {
            return new self(null, false);
        }

        return new self(base64_decode($payload->string('contentBase64'), true) ?: '', true);
    }
}
