<?php

declare(strict_types=1);

namespace App\DTOs\Databases;

use App\Engines\Payload;

final readonly class PreparedPackages
{
    /**
     * @param  list<ExtensionPackage>  $packages
     * @param  list<string>  $preparedFrom
     */
    public function __construct(
        public string $imageDigest,
        public array $packages,
        public array $preparedFrom,
    ) {}

    public static function fromPayload(Payload $payload): self
    {
        $packages = array_map(ExtensionPackage::fromPayload(...), $payload->objects('packages'));

        return new self($payload->string('imageDigest'), $packages, $payload->has('preparedFrom') ? $payload->strings('preparedFrom') : ExtensionPackage::fingerprintsOf($packages));
    }

    /**
     * @param  list<ExtensionPackage>  $packages
     */
    public function isFor(string $imageDigest, array $packages): bool
    {
        return $this->imageDigest === $imageDigest && ExtensionPackage::fingerprintsOf($packages) === $this->preparedFrom;
    }

    /**
     * @return array{imageDigest: string, packages: list<array<string, mixed>>, preparedFrom: list<string>}
     */
    public function toArray(): array
    {
        return ['imageDigest' => $this->imageDigest, 'packages' => array_map(fn (ExtensionPackage $package): array => $package->toArray(), $this->packages), 'preparedFrom' => $this->preparedFrom];
    }
}
