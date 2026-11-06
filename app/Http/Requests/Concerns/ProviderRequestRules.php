<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProviderRequestRules
{
    /**
     * @return array<int, Unique|string>
     */
    protected function nameRules(string $table, ?Model $except = null): array
    {
        return ['required', 'string', 'max:60', Rule::unique($table, 'name')->ignore($except?->getKey())];
    }

    /**
     * @return array<mixed>
     */
    protected function credentials(): array
    {
        return $this->array('credentials');
    }
}
