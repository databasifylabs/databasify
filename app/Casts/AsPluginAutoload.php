<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\Plugins\PluginAutoload;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<PluginAutoload, PluginAutoload>
 */
final class AsPluginAutoload implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): PluginAutoload
    {
        $decoded = is_string($value) ? json_decode($value, true) : [];

        return PluginAutoload::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        throw_unless($value instanceof PluginAutoload, InvalidArgumentException::class, 'A plugin autoload must be a PluginAutoload instance.');

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
