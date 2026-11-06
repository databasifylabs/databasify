<?php

declare(strict_types=1);

namespace App\Support\Migrations;

final class NameList
{
    private const int SHOWN = 10;

    /**
     * @param  list<string>  $names
     */
    public static function of(array $names): string
    {
        $shown = implode(', ', array_slice($names, 0, self::SHOWN));
        $hidden = count($names) - self::SHOWN;

        return $hidden > 0 ? "{$shown} and {$hidden} more" : $shown;
    }

    /**
     * @param  non-empty-string  $separator
     * @return list<string>
     */
    public static function parse(string $names, string $separator = ' '): array
    {
        return array_values(array_filter(explode($separator, $names), fn (string $name): bool => $name !== ''));
    }

    /**
     * @return list<string>
     */
    public static function from(mixed $names): array
    {
        return is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
    }
}
