<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final readonly class ExistsInProject implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(private string $model) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Str::isUuid($value) && $this->model::query()->whereKey($value)->exists()) {
            return;
        }

        $fail('validation.exists')->translate();
    }
}
