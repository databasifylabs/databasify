<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Databases\VersionLine;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;

final class FakeImageRegistry implements ImageRegistry
{
    /** @var array<string, array<string, string>> */
    private array $digests = [];

    private bool $down = false;

    public static function withEveryLine(): self
    {
        $registry = new self;
        collect(Database::engines())->each(fn (string $engine) => Database::providerFor($engine)->versions()->each(fn (VersionLine $line) => $registry->publish($engine, "{$line->value}.0")));

        return $registry;
    }

    public static function digestFor(string $engine, string $tag): string
    {
        return 'sha256:'.hash('sha256', Database::providerFor($engine)->imageRepository().":{$tag}");
    }

    public function publish(string $engine, string $tag, ?string $digest = null): self
    {
        $this->digests[Database::providerFor($engine)->imageRepository()][$tag] = $digest ?? self::digestFor($engine, $tag);

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
