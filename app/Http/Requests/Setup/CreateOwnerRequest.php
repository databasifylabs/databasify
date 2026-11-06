<?php

declare(strict_types=1);

namespace App\Http\Requests\Setup;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\DTOs\Setup\NewOwner;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

class CreateOwnerRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @return array<string, array<int, Password|Unique|ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return array_merge($this->profileRules(), ['password' => $this->passwordRules()]);
    }

    public function toOwner(): NewOwner
    {
        return new NewOwner(
            name: $this->string('name')->trim()->value(),
            email: $this->string('email')->trim()->value(),
            password: $this->string('password')->value(),
        );
    }
}
