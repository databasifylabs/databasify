<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\Migrations\MigrationSource;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<MigrationSource, MigrationSource>
 */
final class AsMigrationSource implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): MigrationSource
    {
        $decoded = is_string($value) ? json_decode(Crypt::decryptString($value), true) : [];

        return MigrationSource::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        throw_unless($value instanceof MigrationSource, InvalidArgumentException::class, 'A migration source must be a MigrationSource instance.');

        return Crypt::encryptString(json_encode($value->toArray(), JSON_THROW_ON_ERROR));
    }
}
