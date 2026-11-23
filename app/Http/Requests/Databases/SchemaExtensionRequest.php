<?php

declare(strict_types=1);

namespace App\Http\Requests\Databases;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SchemaExtensionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,63}$/'],
        ];
    }

    public function extensionName(): string
    {
        return $this->string('name')->value();
    }
}
