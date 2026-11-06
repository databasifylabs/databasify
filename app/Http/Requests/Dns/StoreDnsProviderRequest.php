<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\DTOs\Dns\NewDnsProvider;
use App\DTOs\Forms\DynamicForm;
use App\Http\Requests\Concerns\ProviderRequestRules;
use App\Models\DnsProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDnsProviderRequest extends FormRequest
{
    use ProviderRequestRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => $this->nameRules('dns_providers'),
            'type' => ['required', Rule::in(DnsProvider::types())],
        ], $this->providerForm()->rules('credentials', required: true));
    }

    public function toProvider(): NewDnsProvider
    {
        return new NewDnsProvider(
            name: $this->string('name')->value(),
            type: $this->string('type')->value(),
            credentials: $this->providerForm()->values($this->credentials()),
        );
    }

    private function providerForm(): DynamicForm
    {
        $type = $this->string('type')->value();

        return in_array($type, DnsProvider::types(), true) ? DnsProvider::providerFor($type)->form() : DynamicForm::fromArray([]);
    }
}
