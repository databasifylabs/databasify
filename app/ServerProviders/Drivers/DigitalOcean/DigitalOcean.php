<?php

declare(strict_types=1);

namespace App\ServerProviders\Drivers\DigitalOcean;

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

final readonly class DigitalOcean extends AbstractServerDriver
{
    private const int PAGE_SIZE = 200;

    private const array COUNTRIES = [
        'nyc' => 'US', 'sfo' => 'US', 'atl' => 'US', 'ric' => 'US', 'mkc' => 'US', 'mem' => 'US', 'ams' => 'NL', 'sgp' => 'SG', 'lon' => 'GB',
        'fra' => 'DE', 'tor' => 'CA', 'blr' => 'IN', 'syd' => 'AU',
    ];

    public static function id(): string
    {
        return 'digitalocean';
    }

    public function verify(): void
    {
        $this->get('/account');
    }

    public function regions(): array
    {
        return array_values(collect($this->get('/regions', ['per_page' => self::PAGE_SIZE])->items('regions'))
            ->filter(fn (Payload $region): bool => $region->bool('available'))
            ->map(fn (Payload $region): ProviderRegion => new ProviderRegion($region->string('slug'), $region->string('name'), self::COUNTRIES[rtrim($region->string('slug'), '0123456789')] ?? null))
            ->sortBy('name')
            ->all());
    }

    public function plans(string $region): array
    {
        return array_values(collect($this->get('/sizes', ['per_page' => self::PAGE_SIZE])->items('sizes'))
            ->filter(fn (Payload $size): bool => $size->bool('available')
                && $size->int('memory') >= self::SMALLEST_MEMORY_MB
                && in_array($region, $size->strings('regions'), true))
            ->map(fn (Payload $size): ProviderPlan => new ProviderPlan(
                id: $size->string('slug'),
                family: $size->string('description'),
                cpus: $size->int('vcpus'),
                memoryMb: $size->int('memory'),
                diskGb: $size->int('disk'),
                monthlyPrice: $size->floatOrNull('price_monthly'),
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
        return $this->networkOf($this->post('/vpcs', ['name' => $name, 'region' => $region])->item('vpc'), $region);
    }

    public function deleteNetwork(string $externalId): void
    {
        ProviderHttp::delete($this->http(true), "/vpcs/{$externalId}");
    }

    public function findKey(string $publicKey): ?string
    {
        $response = ProviderHttp::get($this->http(true), '/account/keys/'.Fingerprint::md5($publicKey));

        return $response->notFound() ? null : Payload::of($response->json())->string('ssh_key.id');
    }

    public function createKey(string $name, string $publicKey): string
    {
        return $this->post('/account/keys', ['name' => $name, 'public_key' => $publicKey])->string('ssh_key.id');
    }

    public function createServer(NewProviderServer $server): string
    {
        return $this->post('/droplets', Arr::whereNotNull([
            'name' => $server->name,
            'region' => $server->region,
            'size' => $server->plan,
            'image' => $server->image,
            'ssh_keys' => [$server->sshKeyId],
            'vpc_uuid' => $server->networkExternalId,
            'ipv6' => false,
            'monitoring' => true,
            'tags' => ['databasify'],
        ]))->string('droplet.id');
    }

    public function findServer(string $id): ProviderServer
    {
        $droplet = $this->get("/droplets/{$id}");
        $addresses = collect($droplet->items('droplet.networks.v4'));

        return new ProviderServer(
            id: $id,
            running: $droplet->string('droplet.status') === 'active',
            publicIp: $addresses->first(fn (Payload $address): bool => $address->string('type') === 'public')?->stringOrNull('ip_address'),
            privateIp: $addresses->first(fn (Payload $address): bool => $address->string('type') === 'private')?->stringOrNull('ip_address'),
        );
    }

    public function deleteServer(string $id): void
    {
        ProviderHttp::delete($this->http(true), "/droplets/{$id}");
    }

    private function networkOf(Payload $vpc, string $region): ProviderNetwork
    {
        return new ProviderNetwork(
            externalId: $vpc->string('id'),
            name: $vpc->string('name'),
            cidr: $vpc->string('ip_range'),
            region: $region,
            isDefault: $vpc->bool('default'),
        );
    }

    private function http(bool $allowMissing = false): PendingRequest
    {
        return ProviderHttp::for('DigitalOcean', 'https://api.digitalocean.com/v2', $this->serverProvider->credentials['token'] ?? '', fn (Payload $error): ?string => $error->stringOrNull('message'), $allowMissing);
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
