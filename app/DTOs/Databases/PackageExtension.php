<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Engines\Payload;

final readonly class PackageExtension
{
    public function __construct(
        public string $name,
        public string $defaultVersion,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->matching('name', '/^[a-z0-9_]+$/'), $payload->string('defaultVersion'));
    }

    /**
     * @return array{name: string, defaultVersion: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'defaultVersion' => $this->defaultVersion];
    }
}
