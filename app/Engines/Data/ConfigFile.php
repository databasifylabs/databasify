<?php

declare(strict_types=1);

namespace App\Engines\Data;

final readonly class ConfigFile
{
    public function __construct(
        public string $path,
        public int $mode,
        public string $content,
    ) {}

    /**
     * @return array{path: string, mode: int, content: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'mode' => $this->mode, 'content' => $this->content];
    }
}
