<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

final readonly class MySqlSql
{
    private const array ESCAPES = ['\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z'];

    public function string(string $value): string
    {
        return "'".strtr(Shell::withoutNul($value), self::ESCAPES)."'";
    }

    public function name(string $value): string
    {
        return '`'.str_replace('`', '``', Shell::withoutNul($value)).'`';
    }
}
