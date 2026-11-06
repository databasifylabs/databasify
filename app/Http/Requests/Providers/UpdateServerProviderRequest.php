<?php

declare(strict_types=1);

namespace App\Http\Requests\Providers;

use App\DTOs\Providers\NewServerProvider;
use App\Http\Requests\Concerns\ProviderRequestRules;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use App\Models\ServerProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServerProviderRequest extends FormRequest
{
    use ProviderRequestRules, ResolvesRouteModel;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $provider = $this->routeModel('provider', ServerProvider::class);

        return array_merge(
            ['name' => $this->nameRules('server_providers', $provider)],
            $provider->provider()->form()->rules('credentials', required: false),
        );
    }

    public function toProvider(ServerProvider $current): NewServerProvider
    {
        return new NewServerProvider(
            name: $this->string('name')->trim()->value(),
            type: $current->type,
            credentials: $current->provider()->form()->values($this->credentials(), $current->credentials),
        );
    }
}
