<?php

declare(strict_types=1);

namespace App\Support\Scripts;

final readonly class Literal
{
    public static function sql(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    public static function toml(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function ini(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.,:\/+\-@%]*$/', $value) === 1 ? $value : self::doubleQuoted($value);
    }

    public static function doubleQuoted(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }

    public static function singleQuoted(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    public static function shell(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    public static function mysqlValue(string $value): string
    {
        if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^([0-9]+)([KMG])$/i', $value, $size) === 1) {
            return (string) ((int) $size[1] * 1024 ** (stripos('KMG', $size[2]) + 1));
        }

        return self::sql($value);
    }
}
