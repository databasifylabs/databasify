<?php

declare(strict_types=1);

namespace App\Engines\Requests;

use App\Contracts\Engines\EngineRequest;
use App\Engines\Responses\FileGet as FileGetReply;

/**
 * @implements EngineRequest<FileGetReply>
 */
final readonly class FileGet implements EngineRequest
{
    public function __construct(
        public string $path,
    ) {}

    public function command(): string
    {
        return 'file get';
    }

    public function toPayload(): array
    {
        return ['path' => $this->path];
    }

    public function reply(): string
    {
        return FileGetReply::class;
    }
}
