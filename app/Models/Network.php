<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Networks\WireguardPeer;
use App\DTOs\Providers\ProviderNetwork;
use App\Enums\Servers\NetworkKind;
use App\Enums\Servers\NetworkMemberStatus;
use App\Models\Concerns\OrdersByName;
use Carbon\CarbonImmutable;
use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\LazyCollection;

/**
 * @property string $id
 * @property string $name
 * @property string $cidr
 * @property NetworkKind $kind
 * @property int|null $listen_port
 * @property string|null $server_provider_id
 * @property string|null $external_id
 * @property string|null $region
 * @property-read Collection<int, Server> $servers
 * @property-read ServerProvider|null $serverProvider
 * @property-read int|null $servers_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'cidr', 'kind', 'listen_port', 'server_provider_id', 'external_id', 'region'])]
#[UseFactory(NetworkFactory::class)]
class Network extends Model
{
    /** @use HasFactory<NetworkFactory> */
    use HasFactory, HasUuids;

    use OrdersByName;

    public const int LISTEN_PORT = 51820;

    public const string WIREGUARD_POOL = '100.64.0.0/10';

    public const int DEFAULT_BLOCK_PREFIX = 24;

    public const array BLOCK_PREFIXES = [24, 23, 22, 20, 16];

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function wireguardMembers(): HasMany
    {
        return $this->servers()->withWireguardKey()->orderBy('id');
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function connectedServers(): HasMany
    {
        return $this->servers()->where('network_status', NetworkMemberStatus::Connected);
    }

    /**
     * @return BelongsTo<ServerProvider, $this>
     */
    public function serverProvider(): BelongsTo
    {
        return $this->belongsTo(ServerProvider::class);
    }

    public function isWireguard(): bool
    {
        return $this->kind === NetworkKind::Wireguard;
    }

    /**
     * @return list<WireguardPeer>
     */
    public function peersOf(Server $server): array
    {
        return array_values($this->wireguardMembers()
            ->whereKeyNot($server->id)
            ->get()
            ->map(fn (Server $peer): ?WireguardPeer => $peer->wireguardPeerOn($this))
            ->filter()
            ->all());
    }

    public static function recordFromProvider(ServerProvider $provider, ProviderNetwork $network): self
    {
        return self::query()->updateOrCreate(
            ['server_provider_id' => $provider->id, 'external_id' => $network->externalId],
            ['name' => $network->name, 'cidr' => $network->cidr, 'region' => $network->region, 'kind' => NetworkKind::Provider],
        );
    }

    public function listenPort(): int
    {
        return $this->listen_port ?? self::LISTEN_PORT;
    }

    public static function freeName(): string
    {
        $names = self::query()->pluck('name');
        $number = LazyCollection::range(0, $names->count())->first(fn (int $number): bool => $names->doesntContain("databasify-{$number}"));

        return "databasify-{$number}";
    }

    /**
     * @param  Builder<Network>  $query
     */
    #[Scope]
    protected function wireguard(Builder $query): void
    {
        $query->where('kind', NetworkKind::Wireguard);
    }

    /**
     * @param  Builder<Network>  $query
     */
    #[Scope]
    protected function withMembers(Builder $query): void
    {
        $query->whereHas('servers', fn (Builder $servers) => $servers->withWireguardKey());
    }

    protected function casts(): array
    {
        return [
            'kind' => NetworkKind::class,
            'listen_port' => 'integer',
        ];
    }
}
