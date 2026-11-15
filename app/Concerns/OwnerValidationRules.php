<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

trait OwnerValidationRules
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @return array<string, array<int, Password|Unique|ValidationRule|array<mixed>|string>>
     */
    protected function ownerRules(): array
    {
        return [...$this->profileRules(), 'password' => $this->passwordRules()];
    }
}
