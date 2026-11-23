<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Engines\Payload;

final readonly class PackageCandidate
{
    public function __construct(
        public string $package,
        public ?string $installed,
        public ?string $candidate,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        return new self($payload->string('package'), $payload->nullableString('installed'), $payload->nullableString('candidate'));
    }

    /**
     * @return array{package: string, installed: string|null, candidate: string|null}
     */
    public function toArray(): array
    {
        return ['package' => $this->package, 'installed' => $this->installed, 'candidate' => $this->candidate];
    }
}
