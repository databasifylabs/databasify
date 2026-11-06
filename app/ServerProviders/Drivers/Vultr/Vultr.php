<?php

declare(strict_types=1);

namespace App\ServerProviders\Drivers\Vultr;

use App\DTOs\Providers\NewProviderServer;
use App\DTOs\Providers\ProviderNetwork;
use App\DTOs\Providers\ProviderPlan;
use App\DTOs\Providers\ProviderRegion;
use App\DTOs\Providers\ProviderServer;
use App\ServerProviders\Drivers\AbstractServerDriver;
use App\Services\Http\Payload;
use App\Services\Providers\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;

final readonly class Vultr extends AbstractServerDriver
{
    private const int PAGE_SIZE = 500;

    private const string NO_ADDRESS = '0.0.0.0';

    private const array FAMILIES = [
        'vc2' => 'Regular Cloud Compute',
        'vhf' => 'High Frequency',
        'vhp' => 'High Performance',
        'voc' => 'Optimized Cloud Compute',
        'vdc' => 'Dedicated Cloud',
    ];

    public static function id(): string
    {
        return 'vultr';
    }

    public function verify(): void
    {
        $this->get('/account');
    }

    public function regions(): array
    {
        return array_values(collect($this->get('/regions', ['per_page' => self::PAGE_SIZE])->items('regions'))
            ->map(fn (Payload $region): ProviderRegion => new ProviderRegion($region->string('id'), $region->string('city'), $region->stringOrNull('country')))
            ->sortBy('name')
            ->all());
    }

    public function plans(string $region): array
    {
        $inStock = $this->get("/regions/{$region}/availability")->strings('available_plans');

        return array_values(collect($this->get('/plans', ['type' => 'all', 'per_page' => self::PAGE_SIZE])->items('plans'))
            ->filter(fn (Payload $plan): bool => $this->isOffered($plan, $inStock))
            ->map(fn (Payload $plan): ProviderPlan => new ProviderPlan(
                id: $plan->string('id'),
                family: $this->familyOf($plan),
                cpus: $plan->int('vcpu_count'),
                memoryMb: $plan->int('ram'),
                diskGb: $plan->int('disk'),
                monthlyPrice: $plan->floatOrNull('monthly_cost'),
            ))
            ->sortBy('monthlyPrice')
            ->all());
    }

    public function networks(string $region): array
    {
        return array_values(collect($this->get('/vpcs', ['per_page' => self::PAGE_SIZE])->items('vpcs'))
            ->filter(fn (Payload $vpc): bool => $vpc->string('region') === $region)
            ->map(fn (Payload $vpc): ProviderNetwork => $this->networkOf($vpc, $region))
            ->all());
    }

    public function createNetwork(string $region, string $name): ProviderNetwork
    {
        return $this->networkOf($this->post('/vpcs', ['region' => $region, 'description' => $name])->item('vpc'), $region);
    }

    public function deleteNetwork(string $externalId): void
    {
        ProviderHttp::delete($this->http(true), "/vpcs/{$externalId}");
    }

    public function findKey(string $publicKey): ?string
    {
        return collect($this->get('/ssh-keys', ['per_page' => self::PAGE_SIZE])->items('ssh_keys'))
            ->first(fn (Payload $key): bool => $this->keyBodyOf($key->string('ssh_key')) === $this->keyBodyOf($publicKey))
            ?->string('id');
    }

    public function createKey(string $name, string $publicKey): string
    {
        return $this->post('/ssh-keys', ['name' => $name, 'ssh_key' => $publicKey])->string('ssh_key.id');
    }

    public function createServer(NewProviderServer $server): string
    {
        return $this->post('/instances', Arr::whereNotNull([
            'region' => $server->region,
            'plan' => $server->plan,
            'os_id' => $server->image,
            'label' => $server->name,
            'hostname' => $server->name,
            'sshkey_id' => [$server->sshKeyId],
            'attach_vpc' => $server->networkExternalId === null ? null : [$server->networkExternalId],
            'enable_ipv6' => false,
            'backups' => 'disabled',
            'tags' => ['databasify'],
        ]))->string('instance.id');
    }

    public function findServer(string $id): ProviderServer
    {
        $instance = $this->get("/instances/{$id}");
        $publicIp = $instance->stringOrNull('instance.main_ip');

        return new ProviderServer(
            id: $id,
            running: $instance->string('instance.status') === 'active' && $instance->string('instance.power_status') === 'running',
            publicIp: $publicIp === self::NO_ADDRESS ? null : $publicIp,
            privateIp: $this->get("/instances/{$id}/vpcs")->stringOrNull('vpcs.0.ip_address'),
        );
    }

    public function deleteServer(string $id): void
    {
        ProviderHttp::delete($this->http(true), "/instances/{$id}");
    }

    private function networkOf(Payload $vpc, string $region): ProviderNetwork
    {
        return new ProviderNetwork(
            externalId: $vpc->string('id'),
            name: $vpc->string('description') === '' ? $vpc->string('id') : $vpc->string('description'),
            cidr: $vpc->string('v4_subnet').'/'.$vpc->string('v4_subnet_mask'),
            region: $region,
        );
    }

    /**
     * @param  list<string>  $inStock
     */
    private function isOffered(Payload $plan, array $inStock): bool
    {
        return array_key_exists($plan->string('type'), self::FAMILIES)
            && $plan->int('ram') >= self::SMALLEST_MEMORY_MB
            && in_array($plan->string('id'), $inStock, true);
    }

    private function familyOf(Payload $plan): string
    {
        $family = self::FAMILIES[$plan->string('type')] ?? $plan->string('type');

        return match (true) {
            str_contains($plan->string('id'), '-amd') => "{$family} AMD",
            str_contains($plan->string('id'), '-intel') => "{$family} Intel",
            default => $family,
        };
    }

    private function keyBodyOf(string $publicKey): string
    {
        return explode(' ', trim($publicKey))[1] ?? '';
    }

    private function http(bool $allowMissing = false): PendingRequest
    {
        return ProviderHttp::for('Vultr', 'https://api.vultr.com/v2', $this->serverProvider->credentials['token'] ?? '', fn (Payload $error): ?string => $error->stringOrNull('error'), $allowMissing);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): Payload
    {
        return Payload::of(ProviderHttp::get($this->http(), $path, $query)->json());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body): Payload
    {
        return Payload::of(ProviderHttp::post($this->http(), $path, $body)->json());
    }
}
