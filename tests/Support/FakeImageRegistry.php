<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Images\ImageLabels;

final readonly class FakeImageRegistry implements ImageRegistry
{
    /**
     * @param  array<string, string|null>  $sdkByRelease
     */
    public function __construct(private array $sdkByRelease) {}

    public function tagsOf(string $repository): array
    {
        return array_map(strval(...), array_keys($this->sdkByRelease));
    }

    public function digestOf(string $repository, string $tag): string
    {
        return 'sha256:'.hash('sha256', $tag);
    }

    public function labelsOf(string $repository, string $digest): ImageLabels
    {
        $release = collect(array_keys($this->sdkByRelease))->first(fn (string|int $tag): bool => $this->digestOf($repository, (string) $tag) === $digest);

        return new ImageLabels($release === null ? null : $this->sdkByRelease[$release]);
    }
}
