<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Server;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

final readonly class AvailableServer implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $available = is_string($value) && Str::isUuid($value) && Server::query()->available()->whereKey($value)->exists();

        if (! $available) {
            $fail('Pick a ready server that is not already part of a database.');
        }
    }
}
