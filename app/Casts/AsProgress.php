<?php

declare(strict_types=1);

namespace App\Casts;

use App\Engines\Data\Progress;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<Progress|null, mixed>
 */
final class AsProgress implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Progress
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? Progress::fromArray($decoded) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        throw_unless($value instanceof Progress, InvalidArgumentException::class, 'Progress must be a Progress instance.');

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
