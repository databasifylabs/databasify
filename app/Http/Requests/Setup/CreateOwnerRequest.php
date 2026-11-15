<?php

declare(strict_types=1);

namespace App\Http\Requests\Setup;

use App\Concerns\OwnerValidationRules;
use App\DTOs\Setup\NewOwner;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

class CreateOwnerRequest extends FormRequest
{
    use OwnerValidationRules;

    /**
     * @return array<string, array<int, Password|Unique|ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return $this->ownerRules();
    }

    public function toOwner(): NewOwner
    {
        return NewOwner::fromInput($this->safe());
    }
}
