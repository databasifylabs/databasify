<?php

declare(strict_types=1);

namespace App\Contracts\Engines;

interface SqlQuoting
{
    public function string(string $value): string;

    public function name(string $value): string;
}
