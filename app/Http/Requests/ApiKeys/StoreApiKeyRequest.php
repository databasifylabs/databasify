<?php

declare(strict_types=1);

namespace App\Http\Requests\ApiKeys;

use App\DTOs\Api\NewApiKey;
use App\Enums\Api\AccessLevel;
use App\Enums\Api\ApiArea;
use App\Enums\Api\ApiKeyExpiry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreApiKeyRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'expiry' => ['required', Rule::enum(ApiKeyExpiry::class)],
            'permissions' => ['required', 'array'],
            ...collect(ApiArea::cases())->mapWithKeys(fn (ApiArea $area): array => [
                "permissions.{$area->value}" => ['required', $area->isReadOnly() ? Rule::enum(AccessLevel::class)->except(AccessLevel::Write) : Rule::enum(AccessLevel::class)],
            ])->all(),
            'projects' => ['required', $this->limitsProjects() ? 'array' : Rule::in(['all'])],
            'projects.*' => ['uuid', Rule::exists('projects', 'id')],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->scopes() === []) {
                    $validator->errors()->add('permissions', 'Give the key access to at least one area.');
                }
            },
        ];
    }

    public function toNewApiKey(): NewApiKey
    {
        return new NewApiKey(
            $this->string('name')->value(),
            $this->enum('expiry', ApiKeyExpiry::class) ?? ApiKeyExpiry::Days30,
            $this->scopes(),
            $this->limitsProjects() ? array_values(array_filter($this->array('projects'), is_string(...))) : null,
        );
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        return array_values(collect(ApiArea::cases())
            ->map(fn (ApiArea $area): ?string => match ($this->enum("permissions.{$area->value}", AccessLevel::class)) {
                AccessLevel::Write => $area->writeScope(),
                AccessLevel::Read => $area->readScope(),
                default => null,
            })
            ->filter()
            ->all());
    }

    private function limitsProjects(): bool
    {
        return is_array($this->input('projects'));
    }
}
