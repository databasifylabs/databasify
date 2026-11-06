<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

const NATIVE_TOOLS = '/\b(systemctl|systemd-run|journalctl|apt-get|apt-mark|dpkg|runuser)\b|\bdnf /';

/**
 * @return array<string, array{tool: string, reason: string}>
 */
function deliberateNativeUses(): array
{
    return [
        'engines/mariadb/lifecycle/fingerprint.blade.php' => ['tool' => 'dpkg', 'reason' => 'reads the Galera package version inside the Ubuntu-based MariaDB image, changing it would restart every running node once'],
        'engines/valkey/config/host-tuning.blade.php' => ['tool' => 'systemctl', 'reason' => 'runs on the host, a boot unit turns transparent huge pages off before Docker starts'],
        'engines/valkey/lifecycle/cleanup.blade.php' => ['tool' => 'systemctl', 'reason' => 'runs on the host when a node is erased and removes that boot unit'],
        'engines/redis/config/host-tuning.blade.php' => ['tool' => 'systemctl', 'reason' => 'runs on the host, a boot unit turns transparent huge pages off before Docker starts'],
        'engines/redis/lifecycle/cleanup.blade.php' => ['tool' => 'systemctl', 'reason' => 'runs on the host when a node is erased and removes that boot unit'],
    ];
}

/**
 * @return Collection<int, string>
 */
function nativeToolUsesIn(SplFileInfo $script): Collection
{
    $relative = Str::after($script->getPathname(), resource_path('views/ssh').'/');
    $allowed = deliberateNativeUses()[$relative]['tool'] ?? null;

    return collect(explode("\n", $script->getContents()))
        ->map(fn (string $line, int $index): ?string => preg_match_all(NATIVE_TOOLS, $line, $matches) > 0 && collect($matches[0])->contains(fn (string $tool): bool => trim($tool) !== $allowed) ? "{$relative}:".($index + 1).': '.trim($line) : null)
        ->filter()
        ->values();
}

it('keeps host package managers and systemd out of engine and database scripts', function () {
    $offenders = collect([resource_path('views/ssh/engines'), resource_path('views/ssh/databases')])
        ->flatMap(fn (string $directory): array => File::allFiles($directory))
        ->flatMap(nativeToolUsesIn(...))
        ->values();

    expect($offenders->all())->toBe([]);
});

it('only excuses scripts that still need their native tool', function () {
    $stale = collect(deliberateNativeUses())
        ->reject(fn (array $use, string $path): bool => File::exists(resource_path("views/ssh/{$path}")) && str_contains(File::get(resource_path("views/ssh/{$path}")), $use['tool']))
        ->keys();

    expect($stale->all())->toBe([]);
});
