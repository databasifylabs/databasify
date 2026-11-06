<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use App\Enums\Databases\PasswordEncryption;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePasswordEncryptionRequest extends FormRequest
{
    use ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password_encryption' => ['required', Rule::enum(PasswordEncryption::class)->only($this->routeDatabase()->passwordEncryptions())],
        ];
    }

    public function encryption(): PasswordEncryption
    {
        return PasswordEncryption::from($this->string('password_encryption')->value());
    }
}
