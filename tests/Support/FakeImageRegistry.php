<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Databases\VersionLine;
use App\Engines\Engine;
use App\Engines\EngineCatalog;
use App\Exceptions\Images\ImageUnavailable;

final class FakeImageRegistry implements ImageRegistry
{
    /** @var array<string, array<string, string>> */
    private array $digests = [];

    private bool $down = false;

    public static function withEveryLine(): self
    {
        $registry = new self;
        EngineCatalog::all()->each(fn (Engine $engine) => collect($engine->lines)->each(fn (VersionLine $line) => $registry->publish($engine->id, "{$line->value}.0")));

        return $registry;
    }

    public static function digestFor(string $engine, string $tag): string
    {
        return 'sha256:'.hash('sha256', EngineCatalog::find($engine)->imageRepository.":{$tag}");
    }

    public function publish(string $engine, string $tag, ?string $digest = null): self
    {
        return $this->publishIn(EngineCatalog::find($engine)->imageRepository, $tag, $digest ?? self::digestFor($engine, $tag));
    }

    public function publishIn(string $repository, string $tag, string $digest): self
    {
        $this->digests[$repository][$tag] = $digest;

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

    private function ensureUp(string $repository): void
    {
        if ($this->down) {
            throw ImageUnavailable::unreachable($repository);
        }
    }
}
