<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Databases\VersionLine;
use App\DTOs\Images\ImageLabels;
use App\Engines\Engine;
use App\Engines\EngineCatalog;
use App\Exceptions\Images\ImageUnavailable;

final class FakeImageRegistry implements ImageRegistry
{
    private const string SDK = '0.2.0';

    private const string HOST_REPOSITORY = 'ghcr.io/databasifylabs/engine-sdk';

    /** @var array<string, array<string, string>> */
    private array $digests = [];

    /** @var array<string, array<string, string|null>> */
    private array $sdks = [];

    private bool $down = false;

    public static function withEveryLine(): self
    {
        $registry = new self;
        $registry->publishIn(self::HOST_REPOSITORY, '0.2.0', self::digestIn(self::HOST_REPOSITORY, '0.2.0'));
        EngineCatalog::all()->each(fn (Engine $engine) => collect($engine->lines)->each(fn (VersionLine $line) => $registry->publish($engine->id, "{$line->value}.0")));

        return $registry;
    }

    public static function digestFor(string $engine, string $tag): string
    {
        return self::digestIn(EngineCatalog::find($engine)->imageRepository, $tag);
    }

    public static function digestIn(string $repository, string $tag): string
    {
        return 'sha256:'.hash('sha256', "{$repository}:{$tag}");
    }

    public function publish(string $engine, string $tag, ?string $digest = null, ?string $sdk = self::SDK): self
    {
        return $this->publishIn(EngineCatalog::find($engine)->imageRepository, $tag, $digest ?? self::digestFor($engine, $tag), $sdk);
    }

    public function publishIn(string $repository, string $tag, string $digest, ?string $sdk = self::SDK): self
    {
        $this->digests[$repository][$tag] = $digest;
        $this->sdks[$repository][$digest] = $sdk;

        return $this;
    }

    public function goDown(): self
    {
        $this->down = true;

        return $this;
    }

    public function tagsOf(string $repository): array
    {
        $this->ensureUp($repository);

        return array_keys($this->digests[$repository] ?? []);
    }

    public function digestOf(string $repository, string $tag): string
    {
        $this->ensureUp($repository);

        return $this->digests[$repository][$tag] ?? throw ImageUnavailable::answered($repository, 404);
    }

    public function labelsOf(string $repository, string $digest): ImageLabels
    {
        $this->ensureUp($repository);

        return new ImageLabels($this->sdks[$repository][$digest] ?? null);
    }

    private function ensureUp(string $repository): void
    {
        if ($this->down) {
            throw ImageUnavailable::unreachable($repository);
        }
    }
}
