<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\DTOs\Dns\NewDnsProvider;
use App\Http\Requests\Concerns\ProviderRequestRules;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use App\Models\DnsProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDnsProviderRequest extends FormRequest
{
    use ProviderRequestRules, ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $provider = $this->routeModel('provider', DnsProvider::class);

        return array_merge(
            ['name' => $this->nameRules('dns_providers', $provider)],
            $provider->provider()->form()->rules('credentials', required: false),
        );
    }

    public function toProvider(DnsProvider $current): NewDnsProvider
    {
        return new NewDnsProvider(
            name: $this->string('name')->trim()->value(),
            type: $current->type,
            credentials: $current->provider()->form()->values($this->credentials(), $current->credentials),
        );
    }
}
