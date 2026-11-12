<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Contracts\Engines\SqlQuoting;

final readonly class MySqlSql implements SqlQuoting
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
