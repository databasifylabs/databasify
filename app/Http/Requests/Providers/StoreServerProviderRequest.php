<?php

declare(strict_types=1);

namespace App\Http\Requests\Providers;

use App\DTOs\Forms\DynamicForm;
use App\DTOs\Providers\NewServerProvider;
use App\Http\Requests\Concerns\ProviderRequestRules;
use App\Models\ServerProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerProviderRequest extends FormRequest
{
    use ProviderRequestRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => $this->nameRules('server_providers'),
            'type' => ['required', Rule::in(ServerProvider::types())],
        ], $this->providerForm()->rules('credentials', required: true));
    }

    public function toProvider(): NewServerProvider
    {
        return new NewServerProvider(
            name: $this->string('name')->value(),
            type: $this->string('type')->value(),
            credentials: $this->providerForm()->values($this->credentials()),
        );
    }

    private function providerForm(): DynamicForm
    {
        $type = $this->string('type')->value();

        return in_array($type, ServerProvider::types(), true) ? ServerProvider::providerFor($type)->form() : DynamicForm::fromArray([]);
    }
}
