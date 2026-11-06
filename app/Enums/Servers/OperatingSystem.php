<?php

declare(strict_types=1);

namespace App\Enums\Servers;

use App\Models\ServerProvider;
use App\ServerProviders\Contracts\ServerDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

enum OperatingSystem: string
{
    case Ubuntu2404 = 'ubuntu-24.04';
    case Ubuntu2604 = 'ubuntu-26.04';
    case Debian12 = 'debian-12';
    case Debian13 = 'debian-13';
    case Rocky9 = 'rocky-9';
    case Alma9 = 'almalinux-9';

    public static function supportedSummary(): string
    {
        return collect(self::cases())
            ->groupBy(fn (self $system): string => $system->distributionName())
            ->map(fn (Collection $systems, string $name): string => $name.' '.$systems->map(fn (self $system): string => $system->version())->implode('/'))
            ->values()
            ->join(', ', ' or ');
    }

    public static function fromRelease(string $id, string $versionId): ?self
    {
        return collect(self::cases())->first(fn (self $system): bool => $system->matchesRelease($id, $versionId));
    }

    public static function distributionOfName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return collect(self::cases())
            ->first(fn (self $system): bool => str_starts_with(strtolower($name), strtolower($system->distributionName())))
            ?->distribution();
    }

    public static function iconOf(?string $distribution): ?string
    {
        return $distribution === null ? null : asset("assets/icons/os/{$distribution}.svg");
    }

    public function distribution(): string
    {
        return Str::before($this->value, '-');
    }

    public function version(): string
    {
        return Str::after($this->value, '-');
    }

    public function label(): string
    {
        $label = "{$this->distributionName()} {$this->version()}";

        return $this->distribution() === 'ubuntu' ? "{$label} LTS" : $label;
    }

    public function distributionName(): string
    {
        return match ($this) {
            self::Ubuntu2404, self::Ubuntu2604 => 'Ubuntu',
            self::Debian12, self::Debian13 => 'Debian',
            self::Rocky9 => 'Rocky Linux',
            self::Alma9 => 'AlmaLinux',
        };
    }

    public function family(): OsFamily
    {
        return match ($this) {
            self::Ubuntu2404, self::Ubuntu2604, self::Debian12, self::Debian13 => OsFamily::Debian,
            self::Rocky9, self::Alma9 => OsFamily::Rhel,
        };
    }

    public function isRecommended(): bool
    {
        return $this === self::Ubuntu2404;
    }

    /**
     * @return list<string>
     */
    private function releasePatterns(): array
    {
        $release = "{$this->distribution()}:{$this->version()}";

        return $this->family() === OsFamily::Rhel ? [$release, "{$release}.*"] : [$release];
    }

    public function matchesRelease(string $id, string $versionId): bool
    {
        return collect($this->releasePatterns())->contains(fn (string $pattern): bool => fnmatch($pattern, "{$id}:{$versionId}"));
    }

    /**
     * @return array{value: string, label: string, version: string, distribution: string, family: string, recommended: bool, icon: string|null, providers: list<string>}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'version' => $this->version(),
            'distribution' => $this->distribution(),
            'family' => $this->family()->value,
            'recommended' => $this->isRecommended(),
            'icon' => self::iconOf($this->distribution()),
            'providers' => array_keys(array_filter(ServerProvider::providers(), fn (ServerDriver $provider): bool => $provider->image($this) !== null)),
        ];
    }
}
