<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\Databases\EngineImage;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<EngineImage|null, mixed>
 */
final class AsEngineImage implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?EngineImage
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? EngineImage::fromArray($decoded) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        throw_unless($value instanceof EngineImage, InvalidArgumentException::class, 'An engine image must be an EngineImage instance.');

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
