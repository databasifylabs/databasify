<?php

declare(strict_types=1);

namespace App\Http\Requests\Servers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TerminalInputRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => ['required', 'string', 'max:131072', 'regex:/^[A-Za-z0-9+\/]+={0,2}$/'],
        ];
    }

    public function typed(): string
    {
        return (string) base64_decode($this->string('data')->value(), true);
    }
}
