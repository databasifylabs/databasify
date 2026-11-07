<?php

declare(strict_types=1);

namespace App\Http\Requests\Providers;

use App\DTOs\Providers\ProviderServerOrder;
use App\DTOs\Servers\PrivateNetworkChoice;
use App\Enums\Servers\OperatingSystem;
use App\Http\Requests\Concerns\ReadsTypedInput;
use App\Models\Network;
use App\Models\ServerProvider;
use App\Rules\ExistsInProject;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreProviderServerRequest extends FormRequest
{
    use ReadsTypedInput;

    public const string NEW_NETWORK = 'new';

    private const string DATABASIFY_PREFIX = 'databasify:';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'server_provider_id' => ['required', 'uuid', new ExistsInProject(ServerProvider::class)],
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:servers,name'],
            'region' => ['required', 'string', 'max:64'],
            'plan' => ['required', 'string', 'max:64'],
            'os' => ['required', Rule::enum(OperatingSystem::class)],
            'network' => ['nullable', 'string', 'max:128', $this->existingDatabasifyNetwork(...)],
            'network_block' => ['exclude_unless:network,'.self::DATABASIFY_PREFIX.self::NEW_NETWORK, 'nullable', 'integer', Rule::in(Network::BLOCK_PREFIXES)],
        ];
    }

    public function toOrder(): ProviderServerOrder
    {
        return new ProviderServerOrder(
            serverProviderId: $this->string('server_provider_id')->value(),
            name: $this->string('name')->value(),
            region: $this->string('region')->value(),
            plan: $this->string('plan')->value(),
            os: $this->enum('os', OperatingSystem::class) ?? OperatingSystem::Ubuntu2404,
            networkExternalId: $this->providerNetworkId(),
            newNetwork: $this->createsProviderNetwork(),
            databasifyNetwork: $this->databasifyNetwork(),
        );
    }

    private function createsProviderNetwork(): bool
    {
        return $this->string('network')->value() === self::NEW_NETWORK;
    }

    private function providerNetworkId(): ?string
    {
        if ($this->createsProviderNetwork() || $this->databasifyNetwork() !== null) {
            return null;
        }

        return $this->stringOrNull('network');
    }

    private function databasifyNetwork(): ?PrivateNetworkChoice
    {
        $network = $this->string('network')->value();

        if (! Str::startsWith($network, self::DATABASIFY_PREFIX)) {
            return null;
        }

        $id = Str::after($network, self::DATABASIFY_PREFIX);

        return $id === self::NEW_NETWORK ? PrivateNetworkChoice::newWireguard($this->integerOr('network_block', Network::DEFAULT_BLOCK_PREFIX)) : new PrivateNetworkChoice(networkId: $id);
    }

    private function existingDatabasifyNetwork(string $attribute, mixed $value, Closure $fail): void
    {
        $choice = $this->databasifyNetwork();

        if ($choice?->networkId !== null && ! Network::query()->wireguard()->whereKey($choice->networkId)->exists()) {
            $fail('Pick a Databasify network from the list.');
        }
    }
}
