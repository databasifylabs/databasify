<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

final readonly class UnsupportedSdk
{
    public function __construct(
        public string $sdk,
        public string $range,
        public string $author,
    ) {}

    /**
     * @return array{sdk: string, range: string, author: string}
     */
    public function toArray(): array
    {
        return ['sdk' => $this->sdk, 'range' => $this->range, 'author' => $this->author];
    }
}
