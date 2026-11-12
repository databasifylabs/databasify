<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Contracts\Engines\SqlQuoting;
use LogicException;

final readonly class EnvOnlyQuoting implements SqlQuoting
{
    public function string(string $value): string
    {
        throw new LogicException('This engine reads every value from the environment; never quote it into a script.');
    }

    public function name(string $value): string
    {
        return $this->string($value);
    }
}
