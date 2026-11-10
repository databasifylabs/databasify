<?php

declare(strict_types=1);

namespace App\Casts;

use App\Engines\Data\EngineField;
use App\Engines\Payload;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<list<EngineField>, mixed>
 */
final class AsEngineFields implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<EngineField>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        return array_map(EngineField::fromPayload(...), (new Payload(['fields' => $decoded]))->objects('fields'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        throw_unless(is_array($value), InvalidArgumentException::class, 'Config fields must be a list of EngineField instances.');

        return json_encode(array_map(fn (mixed $field): array => $field instanceof EngineField ? $field->toArray() : throw new InvalidArgumentException('Config fields must be EngineField instances.'), $value), JSON_THROW_ON_ERROR);
    }
}
