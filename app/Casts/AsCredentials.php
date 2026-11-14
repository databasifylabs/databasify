<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<array<string, string>, array<string, string>>
 */
final class AsCredentials implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $json = new AsSecret()->get($model, $key, $value, $attributes);

        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $credentials = [];

        foreach ($decoded as $name => $credential) {
            if (is_string($name) && is_string($credential)) {
                $credentials[$name] = $credential;
            }
        }

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        throw_unless(is_array($value), InvalidArgumentException::class, 'Credentials must be an array of strings.');

        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
