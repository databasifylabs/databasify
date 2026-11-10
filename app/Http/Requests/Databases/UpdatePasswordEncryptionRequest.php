<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

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
            'password_encryption' => ['required', 'string', Rule::in($this->routeDatabase()->passwordEncryptions())],
        ];
    }

    public function encryption(): string
    {
        return $this->string('password_encryption')->value();
    }
}
