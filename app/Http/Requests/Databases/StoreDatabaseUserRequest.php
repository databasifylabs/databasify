<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatabaseUserRequest extends FormRequest
{
    use ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/',
                Rule::notIn($this->routeDatabase()->engine_capabilities->reservedUsers ?? []),
                Rule::unique('database_users', 'name')->where('database_id', $this->routeDatabase()->id),
            ],
            'password_encryption' => ['nullable', 'string', Rule::in($this->routeDatabase()->passwordEncryptions())],
        ];
    }

    public function userName(): string
    {
        return $this->string('name')->value();
    }

    public function encryption(): ?string
    {
        return $this->filled('password_encryption') ? $this->string('password_encryption')->value() : null;
    }
}
