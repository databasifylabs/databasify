<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\Databases\DatabaseSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<DatabaseSettings, DatabaseSettings>
 */
final class AsDatabaseSettings implements CastsAttributes, ComparesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): DatabaseSettings
    {
        if (! is_string($value)) {
            return new DatabaseSettings([]);
        }

        $decoded = json_decode(Crypt::decryptString($value), true);

        return DatabaseSettings::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        throw_unless($value instanceof DatabaseSettings, InvalidArgumentException::class, 'Database settings must be a DatabaseSettings instance.');

        return Crypt::encryptString(json_encode($value->toArray(), JSON_THROW_ON_ERROR));
    }

    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        return $this->plainTextOf($firstValue) === $this->plainTextOf($secondValue);
    }

    private function plainTextOf(mixed $value): ?string
    {
        return is_string($value) ? Crypt::decryptString($value) : null;
    }
}
