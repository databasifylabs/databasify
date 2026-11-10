<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Enums\Databases\PasswordEncryption;
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
            'password_encryption' => ['nullable', Rule::enum(PasswordEncryption::class)->only($this->routeDatabase()->passwordEncryptions())],
        ];
    }

    public function userName(): string
    {
        return $this->string('name')->value();
    }

    public function encryption(): ?PasswordEncryption
    {
        return $this->enum('password_encryption', PasswordEncryption::class);
    }
}
