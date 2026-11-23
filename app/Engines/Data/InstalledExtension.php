<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\Engines\Payload;

final readonly class InstalledExtension
{
    public function __construct(
        public string $name,
        public string $version,
        public string $defaultVersion,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->string('name'), $payload->string('version'), $payload->string('defaultVersion'));
    }

    public function hasUpdate(): bool
    {
        return version_compare($this->defaultVersion, $this->version) > 0;
    }
}
