<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\Migrations\MigrationPosition;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<MigrationPosition, MigrationPosition>
 */
final class AsMigrationPosition implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): MigrationPosition
    {
        $decoded = is_string($value) ? json_decode($value, true) : [];

        return MigrationPosition::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        throw_unless($value instanceof MigrationPosition, InvalidArgumentException::class, 'A migration position must be a MigrationPosition instance.');

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
