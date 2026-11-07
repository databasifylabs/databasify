<?php

declare(strict_types=1);

namespace App\Http\Requests\Storage;

use App\DTOs\Storage\NewStorageProvider;
use App\Http\Requests\Concerns\ProviderRequestRules;
use App\Http\Requests\Concerns\ReadsTypedInput;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use App\Http\Requests\Concerns\StorageProviderRules;
use App\Models\StorageProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStorageProviderRequest extends FormRequest
{
    use ProviderRequestRules, ReadsTypedInput, ResolvesRouteModel, StorageProviderRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(
            ['name' => $this->nameRules('storage_providers', $this->routeModel('provider', StorageProvider::class))],
            $this->locationRules(),
            ['secret_access_key' => $this->secretRules(required: false)],
        );
    }

    public function toProvider(StorageProvider $current): NewStorageProvider
    {
        return new NewStorageProvider(
            name: $this->string('name')->trim()->value(),
            bucket: $this->bucketWith($this->stringOrNull('secret_access_key') ?? $current->bucket()->secretAccessKey),
        );
    }
}
