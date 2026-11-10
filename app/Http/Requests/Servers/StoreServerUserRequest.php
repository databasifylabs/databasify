<?php

declare(strict_types=1);

namespace App\Http\Requests\Servers;

use App\Http\Requests\Concerns\ReadsTypedInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerUserRequest extends FormRequest
{
    use ReadsTypedInput;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/', Rule::notIn(['root', 'ubuntu', 'admin', 'databasify-engine'])],
            'public_key' => ['nullable', 'string', 'max:2000', 'regex:/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)) [A-Za-z0-9+\/=]+( [A-Za-z0-9@._-]+)?$/'],
        ];
    }

    public function username(): string
    {
        return $this->string('username')->value();
    }

    public function publicKey(): ?string
    {
        return $this->stringOrNull('public_key');
    }
}
