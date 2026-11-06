<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\DTOs\Servers\NewNetwork;
use App\DTOs\Servers\PrivateNetworkChoice;
use App\Models\Network;
use App\Models\Server;
use App\Rules\NetworkCidr;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\In;
use Symfony\Component\HttpFoundation\IpUtils;

trait PrivateNetworkRules
{
    /**
     * @return array<string, array<int, ExcludeIf|In|ValidationRule|Closure|string>>
     */
    protected function privateNetworkRules(?Server $except = null): array
    {
        return [
            'network' => ['nullable', 'string', 'regex:/^(new|wireguard|[0-9a-f-]{36})$/', $this->existingNetwork(...)],
            'network_name' => ['exclude_unless:network,new', 'required', 'string', 'max:60', 'unique:networks,name'],
            'network_cidr' => ['exclude_unless:network,new', 'required', new NetworkCidr],
            'network_block' => ['exclude_unless:network,wireguard', 'nullable', 'integer', Rule::in(Network::BLOCK_PREFIXES)],
            'private_ip' => ['exclude_without:network', Rule::excludeIf(fn (): bool => $this->choosesWireguard()), 'required', 'ipv4', $this->insideChosenNetwork(...), fn (string $attribute, mixed $value, Closure $fail) => $this->unusedIn($value, $fail, $except)],
        ];
    }

    public function toPrivateNetwork(): PrivateNetworkChoice
    {
        $network = $this->stringOrNull('network');

        if ($network === null) {
            return PrivateNetworkChoice::none();
        }

        if ($network === 'wireguard') {
            return PrivateNetworkChoice::newWireguard($this->integerOr('network_block', Network::DEFAULT_BLOCK_PREFIX));
        }

        return new PrivateNetworkChoice(
            networkId: $this->chosenNetworkId(),
            newNetwork: $network === 'new' ? new NewNetwork($this->string('network_name')->value(), $this->string('network_cidr')->value()) : null,
            privateIp: $this->stringOrNull('private_ip'),
        );
    }

    private function choosesWireguard(): bool
    {
        if ($this->stringOrNull('network') === 'wireguard') {
            return true;
        }

        $networkId = $this->chosenNetworkId();

        return $networkId !== null && Network::query()->wireguard()->whereKey($networkId)->exists();
    }

    private function chosenNetworkId(): ?string
    {
        $network = $this->stringOrNull('network');

        return $network !== null && Str::isUuid($network) ? $network : null;
    }

    private function existingNetwork(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Str::isUuid($value) && ! Network::query()->whereKey($value)->exists()) {
            $fail('Pick a network from the list.');
        }
    }

    private function insideChosenNetwork(string $attribute, mixed $value, Closure $fail): void
    {
        $cidr = $this->chosenCidr();

        if (is_string($value) && $cidr !== null && ! IpUtils::checkIp4($value, $cidr)) {
            $fail("Use an address inside {$cidr}.");
        }
    }

    private function unusedIn(mixed $value, Closure $fail, ?Server $except): void
    {
        $networkId = $this->chosenNetworkId();

        if (! is_string($value) || $networkId === null) {
            return;
        }

        $owner = Server::query()->usingPrivateAddress($networkId, $value)->whereKeyNot($except?->id)->first();

        if ($owner !== null) {
            $fail("{$owner->name} already uses {$value} on this network.");
        }
    }

    private function chosenCidr(): ?string
    {
        $networkId = $this->chosenNetworkId();

        return match (true) {
            $this->stringOrNull('network') === 'new' => $this->stringOrNull('network_cidr'),
            $networkId !== null => Network::query()->whereKey($networkId)->first()?->cidr,
            default => null,
        };
    }
}
