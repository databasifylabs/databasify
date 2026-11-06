<?php

declare(strict_types=1);

namespace App\ServerProviders\Drivers\Linode;

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
use Illuminate\Support\Str;
use RuntimeException;

final readonly class Linode extends AbstractServerDriver
{
    private const int PAGE_SIZE = 500;

    private const int ROOT_PASSWORD_LENGTH = 32;

    private const string SUBNET_RANGE = '10.0.0.0/24';

    private const array FAMILIES = [
        'nanode' => 'Shared CPU',
        'standard' => 'Shared CPU',
        'dedicated' => 'Dedicated CPU',
        'highmem' => 'High Memory',
        'premium' => 'Premium CPU AMD',
    ];

    private const array CAPABILITIES = [
        'premium' => 'Premium Plans',
    ];

    public static function id(): string
    {
        return 'linode';
    }

    public function verify(): void
    {
        $this->get('/profile');
    }

    public function regions(): array
    {
        return array_values(collect($this->get('/regions', ['page_size' => self::PAGE_SIZE])->items('data'))
            ->filter(fn (Payload $region): bool => $region->string('status') === 'ok' && in_array('Linodes', $region->strings('capabilities'), true))
            ->map(fn (Payload $region): ProviderRegion => new ProviderRegion($region->string('id'), $region->string('label'), strtoupper($region->string('country'))))
            ->sortBy('name')
            ->all());
    }

    public function plans(string $region): array
    {
        $capabilities = $this->get("/regions/{$region}")->strings('capabilities');

        return array_values(collect($this->get('/linode/types', ['page_size' => self::PAGE_SIZE])->items('data'))
            ->filter(fn (Payload $type): bool => $this->isOffered($type, $capabilities))
            ->map(fn (Payload $type): ProviderPlan => new ProviderPlan(
                id: $type->string('id'),
                family: self::FAMILIES[$type->string('class')],
                cpus: $type->int('vcpus'),
                memoryMb: $type->int('memory'),
                diskGb: intdiv($type->int('disk'), self::MB_PER_GB),
                monthlyPrice: $this->priceOf($type, $region),
            ))
            ->sortBy('monthlyPrice')
            ->all());
    }

    public function networks(string $region): array
    {
        return array_values(collect($this->get('/vpcs', ['page_size' => self::PAGE_SIZE])->items('data'))
            ->filter(fn (Payload $vpc): bool => $vpc->string('region') === $region)
            ->flatMap(fn (Payload $vpc): array => $this->subnetsOf($vpc, $region))
            ->all());
    }

    public function createNetwork(string $region, string $name): ProviderNetwork
    {
        $vpc = $this->post('/vpcs', [
            'label' => $name,
            'region' => $region,
            'subnets' => [['label' => 'databasify', 'ipv4' => self::SUBNET_RANGE]],
        ]);

        return $this->subnetsOf($vpc, $region)[0] ?? throw new RuntimeException("Linode created {$name} without a subnet.");
    }

    public function deleteNetwork(string $externalId): void
    {
        $vpc = collect($this->get('/vpcs', ['page_size' => self::PAGE_SIZE])->items('data'))
            ->first(fn (Payload $vpc): bool => collect($vpc->items('subnets'))->contains(fn (Payload $subnet): bool => $subnet->string('id') === $externalId));

        if ($vpc === null) {
            return;
        }

        ProviderHttp::delete($this->http(true), count($vpc->items('subnets')) === 1
            ? "/vpcs/{$vpc->string('id')}"
            : "/vpcs/{$vpc->string('id')}/subnets/{$externalId}");
    }

    public function findKey(string $publicKey): string
    {
        return Fingerprint::md5($publicKey);
    }

    public function createKey(string $name, string $publicKey): string
    {
        return Fingerprint::md5($publicKey);
    }

    public function createServer(NewProviderServer $server): string
    {
        return $this->post('/linode/instances', Arr::whereNotNull([
            'label' => $server->name,
            'region' => $server->region,
            'type' => $server->plan,
            'image' => $server->image,
            'root_pass' => Str::password(self::ROOT_PASSWORD_LENGTH),
            'authorized_keys' => [$server->publicKey],
            'booted' => true,
            'tags' => ['databasify'],
            'interfaces' => $server->networkExternalId === null ? null : [
                ['purpose' => 'public'],
                ['purpose' => 'vpc', 'subnet_id' => intval($server->networkExternalId)],
            ],
        ]))->string('id');
    }

    public function findServer(string $id): ProviderServer
    {
        $instance = $this->get("/linode/instances/{$id}");
        $vpcInterface = collect($this->get("/linode/instances/{$id}/configs")->items('data.0.interfaces'))
            ->first(fn (Payload $interface): bool => $interface->string('purpose') === 'vpc');

        return new ProviderServer(
            id: $id,
            running: $instance->string('status') === 'running',
            publicIp: $instance->stringOrNull('ipv4.0'),
            privateIp: $vpcInterface?->stringOrNull('ipv4.vpc'),
        );
    }

    public function deleteServer(string $id): void
    {
        ProviderHttp::delete($this->http(true), "/linode/instances/{$id}");
    }

    /**
     * @return list<ProviderNetwork>
     */
    private function subnetsOf(Payload $vpc, string $region): array
    {
        return array_map(fn (Payload $subnet): ProviderNetwork => new ProviderNetwork(
            externalId: $subnet->string('id'),
            name: $vpc->string('label').' / '.$subnet->string('label'),
            cidr: $subnet->string('ipv4'),
            region: $region,
        ), $vpc->items('subnets'));
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function isOffered(Payload $type, array $capabilities): bool
    {
        $class = $type->string('class');

        return array_key_exists($class, self::FAMILIES)
            && $type->int('memory') >= self::SMALLEST_MEMORY_MB
            && in_array(self::CAPABILITIES[$class] ?? 'Linodes', $capabilities, true);
    }

    private function priceOf(Payload $type, string $region): ?float
    {
        $regional = collect($type->items('region_prices'))->first(fn (Payload $price): bool => $price->string('id') === $region);

        return $regional?->floatOrNull('monthly') ?? $type->floatOrNull('price.monthly');
    }

    private function http(bool $allowMissing = false): PendingRequest
    {
        return ProviderHttp::for('Linode', 'https://api.linode.com/v4', $this->serverProvider->credentials['token'] ?? '', fn (Payload $error): ?string => $error->stringOrNull('errors.0.reason'), $allowMissing);
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
