<?php

declare(strict_types=1);

namespace App\Support\Plugins;

final readonly class VersionConstraint
{
    public static function allows(string $constraint, string $version): bool
    {
        return collect(explode('||', $constraint))
            ->contains(fn (string $alternative): bool => self::alternativeAllows($alternative, $version));
    }

    private static function alternativeAllows(string $alternative, string $version): bool
    {
        $parts = preg_split('/[\s,]+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: ['*'];

        return collect($parts)->every(fn (string $part): bool => self::matches($part, $version));
    }

    private static function matches(string $part, string $version): bool
    {
        if ($part === '*') {
            return true;
        }

        if (preg_match('/^(\^|~|>=|<=|!=|>|<|=)?v?(\d+(?:\.\d+){0,2})$/', $part, $match) !== 1) {
            return false;
        }

        [, $operator, $bound] = $match;

        return match ($operator) {
            '^' => version_compare($version, $bound, '>=') && version_compare($version, self::caretCeiling($bound), '<'),
            '~' => version_compare($version, $bound, '>=') && version_compare($version, self::tildeCeiling($bound), '<'),
            '' => version_compare($version, $bound, '=='),
            default => version_compare($version, $bound, $operator === '=' ? '==' : $operator),
        };
    }

    private static function caretCeiling(string $bound): string
    {
        $numbers = array_map(intval(...), explode('.', $bound));
        $nonZero = array_keys(array_filter($numbers, fn (int $number): bool => $number !== 0));
        $index = $nonZero[0] ?? count($numbers) - 1;

        return self::bump($numbers, $index);
    }

    private static function tildeCeiling(string $bound): string
    {
        $numbers = array_map(intval(...), explode('.', $bound));

        return self::bump($numbers, max(0, count($numbers) - 2));
    }

    /**
     * @param  list<int>  $numbers
     */
    private static function bump(array $numbers, int $index): string
    {
        $numbers = array_slice($numbers, 0, $index + 1);
        $numbers[$index]++;

        return implode('.', $numbers);
    }
}
