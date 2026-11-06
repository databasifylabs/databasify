<?php

declare(strict_types=1);

namespace App\Http\Requests\Storage;

use App\DTOs\Storage\NewStorageProvider;
use App\Http\Requests\Concerns\StorageProviderRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreStorageProviderRequest extends FormRequest
{
    use StorageProviderRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(
            ['name' => ['required', 'string', 'max:60', 'unique:storage_providers,name']],
            $this->locationRules(),
            ['secret_access_key' => $this->secretRules(required: true)],
        );
    }

    public function toProvider(): NewStorageProvider
    {
        return new NewStorageProvider(
            name: $this->string('name')->trim()->value(),
            bucket: $this->bucketWith($this->string('secret_access_key')->value()),
        );
    }
}
