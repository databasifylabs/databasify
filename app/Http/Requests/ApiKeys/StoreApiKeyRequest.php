<?php

declare(strict_types=1);

namespace App\Http\Requests\ApiKeys;

use App\DTOs\Api\NewApiKey;
use App\Enums\Api\ApiKeyExpiry;
use App\Http\Requests\Concerns\ChoosesApiAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreApiKeyRequest extends FormRequest
{
    use ChoosesApiAccess;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'expiry' => ['required', Rule::enum(ApiKeyExpiry::class)],
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

    public function toNewApiKey(): NewApiKey
    {
        $grant = $this->toAccessGrant();

        return new NewApiKey(
            $this->string('name')->value(),
            $this->enum('expiry', ApiKeyExpiry::class) ?? ApiKeyExpiry::Days30,
            $grant->scopes,
            $grant->projectIds,
        );
    }
}
