<?php

declare(strict_types=1);

namespace App\Casts;

use App\Engines\Payload;
use App\Engines\Responses\EngineCapabilities;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<EngineCapabilities|null, mixed>
 */
final class AsEngineCapabilities implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?EngineCapabilities
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? EngineCapabilities::fromPayload(new Payload($decoded)) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        throw_unless($value instanceof EngineCapabilities, InvalidArgumentException::class, 'Engine capabilities must be an EngineCapabilities instance.');

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
