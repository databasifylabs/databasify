<?php

declare(strict_types=1);

namespace App\Casts;

use App\Engines\Flows\FlowContext;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<FlowContext, FlowContext>
 */
final class AsFlowContext implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): FlowContext
    {
        $decoded = is_string($value) ? json_decode($value, true) : [];

        return FlowContext::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        throw_unless($value instanceof FlowContext, InvalidArgumentException::class, 'A flow context must be a FlowContext instance.');

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
