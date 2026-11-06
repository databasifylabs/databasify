<?php

declare(strict_types=1);

namespace App\Support\Providers;

use Illuminate\Support\Sleep;

final class LaunchPolls
{
    public const int SECONDS = 10;

    public const int MAX = 60;

    public static function checkLine(int $poll, string $state): string
    {
        return "Check {$poll}/".self::MAX.": {$state}".PHP_EOL;
    }

    public static function pause(): void
    {
        Sleep::for(self::SECONDS)->seconds();
    }

    public static function limit(): string
    {
        return (self::SECONDS * self::MAX / 60).' minutes';
    }
}
