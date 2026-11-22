<?php

declare(strict_types=1);

namespace App\Http\Requests\ConnectedApps;

use App\Http\Requests\Concerns\ChoosesApiAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ApproveConnectedAppRequest extends FormRequest
{
    use ChoosesApiAccess;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'auth_token' => ['required', 'string'],
            ...$this->accessRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->accessAfter()];
    }
}
