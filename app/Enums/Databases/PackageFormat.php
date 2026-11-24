<?php

declare(strict_types=1);

namespace App\Enums\Databases;

enum PackageFormat: string
{
    case Deb = 'deb';
    case Rpm = 'rpm';

    public function repositoryLabel(): string
    {
        return match ($this) {
            self::Deb => 'Apt repository',
            self::Rpm => 'Yum repository',
        };
    }

    public function repositoryPattern(): string
    {
        return match ($this) {
            self::Deb => '/^deb (\[[^\]]*\] )?https:\/\/\S+ \S+( \S+)+$/',
            self::Rpm => '/^https:\/\/\S+$/',
        };
    }

    public function repositoryExample(): string
    {
        return match ($this) {
            self::Deb => 'deb https://example.com/debian trixie main',
            self::Rpm => 'https://example.com/el9/$basearch',
        };
    }

    public function savedRepository(string $line): string
    {
        return match ($this) {
            self::Deb => preg_replace('/^deb \[[^\]]*\] /', 'deb ', $line) ?? $line,
            self::Rpm => $line,
        };
    }
}
