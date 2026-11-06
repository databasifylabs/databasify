<?php

declare(strict_types=1);

namespace App\Support\Databases;

final class SchemaName
{
    public const string CHARSET = '^[A-Za-z][A-Za-z0-9_]*$';

    public const string PATTERN = '/'.self::CHARSET.'/';

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }
}
