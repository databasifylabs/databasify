<?php

declare(strict_types=1);

namespace App\Exceptions\Databases;

use App\DatabaseProviders\Contracts\DatabaseDriver;
use Illuminate\Validation\ValidationException;
use LogicException;

final class MissingCapability extends LogicException
{
    public static function of(DatabaseDriver $engine, string $capability): self
    {
        return new self(match ($capability) {
            'migration' => "{$engine->label()} databases cannot be migrated.",
            'archive' => "{$engine->label()} databases cannot be recovered to a point in time.",
            'accounts' => "{$engine->label()} databases have no users or databases to manage.",
            default => "{$engine->label()} databases cannot use {$capability}.",
        });
    }

    public static function rejectionOf(DatabaseDriver $engine, string $capability): ValidationException
    {
        return ValidationException::withMessages(['database' => self::of($engine, $capability)->getMessage()]);
    }
}
