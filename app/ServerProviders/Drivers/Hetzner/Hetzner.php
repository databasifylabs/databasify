<?php

declare(strict_types=1);

namespace App\ServerProviders\Drivers\Hetzner;

use App\DTOs\Providers\NewProviderServer;
use App\DTOs\Providers\ProviderNetwork;
use App\DTOs\Providers\ProviderPlan;
use App\DTOs\Providers\ProviderRegion;
use App\DTOs\Providers\ProviderServer;
use App\ServerProviders\Drivers\AbstractServerDriver;
use App\Services\Http\Payload;
use App\Services\Providers\ProviderHttp;
use App\Support\Ssh\Fingerprint;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;

final readonly class Hetzner extends AbstractServerDriver
{
    private const int PAGE_SIZE = 50;

    private const string NETWORK_RANGE = '10.0.0.0/16';

    private const string SUBNET_RANGE = '10.0.0.0/24';

    public static function id(): string
    {
        return 'hetzner';
    }

    public function verify(): void
    {
        $this->get('/servers', ['per_page' => 1]);
    }

    public function regions(): array
    {
        return array_values(collect($this->get('/locations', ['per_page' => self::PAGE_SIZE])->items('locations'))
            ->map(fn (Payload $location): ProviderRegion => new ProviderRegion($location->string('name'), $location->string('city'), $location->stringOrNull('country')))
            ->sortBy('name')
            ->all());
    }

    public function plans(string $region): array
    {
        return array_values(collect($this->get('/server_types', ['per_page' => self::PAGE_SIZE])->items('server_types'))
            ->filter(fn (Payload $type): bool => $this->isOffered($type, $region))
            ->map(fn (Payload $type): ProviderPlan => new ProviderPlan(
                id: $type->string('name'),
                family: $this->familyOf($type),
                cpus: $type->int('cores'),
                memoryMb: $this->memoryOf($type),
                diskGb: $type->int('disk'),
                monthlyPrice: $this->priceOf($type, $region),
            ))
            ->sortBy('monthlyPrice')
            ->all());
    }

    public function networks(string $region): array
    {
        $zone = $this->zoneOf($region);

        return array_values(collect($this->get('/networks', ['per_page' => self::PAGE_SIZE])->items('networks'))
            ->filter(fn (Payload $network): bool => collect($network->items('subnets'))->contains(fn (Payload $subnet): bool => $subnet->string('network_zone') === $zone))
            ->map(fn (Payload $network): ProviderNetwork => $this->networkOf($network, $region))
            ->all());
    }

    public function createNetwork(string $region, string $name): ProviderNetwork
    {
        $network = $this->post('/networks', [
            'name' => $name,
            'ip_range' => self::NETWORK_RANGE,
            'subnets' => [['type' => 'cloud', 'network_zone' => $this->zoneOf($region), 'ip_range' => self::SUBNET_RANGE]],
            'labels' => ['managed-by' => 'databasify'],
        ])->item('network');

        return $this->networkOf($network, $region);
    }

    public function deleteNetwork(string $externalId): void
    {
        ProviderHttp::delete($this->http(true), "/networks/{$externalId}");
    }

    public function findKey(string $publicKey): ?string
    {
        return $this->get('/ssh_keys', ['fingerprint' => Fingerprint::md5($publicKey)])->stringOrNull('ssh_keys.0.id');
    }

    public function createKey(string $name, string $publicKey): string
    {
        return $this->post('/ssh_keys', ['name' => $name, 'public_key' => $publicKey])->string('ssh_key.id');
    }

    public function createServer(NewProviderServer $server): string
    {
        return $this->post('/servers', Arr::whereNotNull([
            'name' => $server->name,
            'location' => $server->region,
            'server_type' => $server->plan,
            'image' => $server->image,
            'ssh_keys' => [intval($server->sshKeyId)],
            'networks' => $server->networkExternalId === null ? null : [intval($server->networkExternalId)],
            'labels' => ['managed-by' => 'databasify'],
            'start_after_create' => true,
        ]))->string('server.id');
    }

    public function findServer(string $id): ProviderServer
    {
        $server = $this->get("/servers/{$id}");

        return new ProviderServer(
            id: $id,
            running: $server->string('server.status') === 'running',
            publicIp: $server->stringOrNull('server.public_net.ipv4.ip'),
            privateIp: $server->stringOrNull('server.private_net.0.ip'),
        );
    }

    public function deleteServer(string $id): void
    {
        ProviderHttp::delete($this->http(true), "/servers/{$id}");
    }

    private function zoneOf(string $region): string
    {
        return $this->get('/locations', ['name' => $region])->string('locations.0.network_zone');
    }

    private function networkOf(Payload $network, string $region): ProviderNetwork
    {
        return new ProviderNetwork(
            externalId: $network->string('id'),
            name: $network->string('name'),
            cidr: $network->string('ip_range'),
            region: $region,
        );
    }

    private function isOffered(Payload $type, string $region): bool
    {
        $location = collect($type->items('locations'))->first(fn (Payload $location): bool => $location->string('name') === $region);

        return $type->string('architecture') === 'x86'
            && $this->memoryOf($type) >= self::SMALLEST_MEMORY_MB
            && $location !== null
            && $location->bool('available')
            && ! $this->isRetired($location->stringOrNull('deprecation.unavailable_after'));
    }

    private function isRetired(?string $unavailableAfter): bool
    {
        return $unavailableAfter !== null && Date::parse($unavailableAfter)->isPast();
    }

    private function familyOf(Payload $type): string
    {
        $cpu = $type->string('cpu_type') === 'dedicated' ? 'Dedicated vCPU' : 'Shared vCPU';

        return str_starts_with($type->string('name'), 'cpx') || str_starts_with($type->string('name'), 'ccx') ? "{$cpu} AMD" : $cpu;
    }

    private function memoryOf(Payload $type): int
    {
        return intval(round(($type->floatOrNull('memory') ?? 0) * self::MB_PER_GB));
    }

    private function priceOf(Payload $type, string $region): ?float
    {
        return collect($type->items('prices'))
            ->first(fn (Payload $price): bool => $price->string('location') === $region)
            ?->floatOrNull('price_monthly.gross');
    }

    private function http(bool $allowMissing = false): PendingRequest
    {
        return ProviderHttp::for('Hetzner', 'https://api.hetzner.cloud/v1', $this->serverProvider->credentials['token'] ?? '', fn (Payload $error): ?string => $error->stringOrNull('error.message'), $allowMissing);
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
