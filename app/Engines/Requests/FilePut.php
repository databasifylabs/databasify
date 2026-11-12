<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\Done;

/**
 * @implements EngineRequest<Done>
 */
final readonly class FilePut implements EngineRequest
{
    public function __construct(
        public string $path,
        public string $content,
        public int $mode,
        public ?string $owner,
    ) {}

    public function command(): string
    {
        return 'file put';
    }

    public function toPayload(): array
    {
        return ['path' => $this->path, 'contentBase64' => base64_encode($this->content), 'mode' => $this->mode, 'owner' => $this->owner];
    }

    public function reply(): string
    {
        return Done::class;
    }
}
