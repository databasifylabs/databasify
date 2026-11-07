<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Projects\Movable;
use App\DTOs\Databases\NodeResources;
use App\DTOs\Networks\WireguardPeer;
use App\DTOs\Providers\ProviderServer;
use App\Enums\Servers\NetworkMemberStatus;
use App\Enums\Servers\OperatingSystem;
use App\Enums\Servers\ServerLogStatus;
use App\Enums\Servers\ServerStatus;
use App\Models\Concerns\BelongsToProject;
use App\Models\Concerns\MovesBetweenProjects;
use App\Models\Concerns\OrdersByName;
use App\Observers\ServerObserver;
use App\Services\Ssh\Ssh;
use Carbon\CarbonImmutable;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string|null $public_ip
 * @property string|null $private_ip
 * @property string|null $network_id
 * @property string|null $server_provider_id
 * @property string|null $provider_server_id
 * @property string|null $region
 * @property string|null $plan
 * @property OperatingSystem|null $image
 * @property string|null $wireguard_public_key
 * @property NetworkMemberStatus|null $network_status
 * @property string|null $network_issue
 * @property CarbonImmutable|null $network_checked_at
 * @property-read ServerProvider|null $serverProvider
 * @property-read Network|null $network
 * @property int $ssh_port
 * @property string $ssh_user
 * @property string|null $ssh_password
 * @property string|null $host_key_fingerprint
 * @property ServerStatus $status
 * @property string|null $os
 * @property int|null $cpus
 * @property int|null $memory_mb
 * @property int|null $disk_gb
 * @property Collection<int, string>|null $interface_subnets
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['project_id', 'name', 'public_ip', 'private_ip', 'network_id', 'server_provider_id', 'provider_server_id', 'region', 'plan', 'image', 'wireguard_public_key', 'network_status', 'network_issue', 'network_checked_at', 'ssh_port', 'ssh_user', 'ssh_password', 'host_key_fingerprint', 'status', 'os', 'cpus', 'memory_mb', 'disk_gb', 'interface_subnets'])]
#[Hidden(['ssh_password'])]
#[UseFactory(ServerFactory::class)]
#[ObservedBy(ServerObserver::class)]
class Server extends Model implements Movable
{
    /** @use HasFactory<ServerFactory> */
    use BelongsToProject, HasFactory, HasUuids, MovesBetweenProjects, OrdersByName;

    /**
     * @return HasMany<DatabaseNode, $this>
     */
    public function databaseNodes(): HasMany
    {
        return $this->hasMany(DatabaseNode::class);
    }

    /**
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class)->withoutProject();
    }

    /**
     * @return BelongsTo<ServerProvider, $this>
     */
    public function serverProvider(): BelongsTo
    {
        return $this->belongsTo(ServerProvider::class)->withoutProject();
    }

    public function address(): string
    {
        return $this->public_ip ?? throw new LogicException("{$this->name} has no public address yet.");
    }

    public function distribution(): ?string
    {
        return $this->image?->distribution() ?? OperatingSystem::distributionOfName($this->os);
    }

    /**
     * @return HasMany<ServerUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(ServerUser::class);
    }

    public function resources(): NodeResources
    {
        return NodeResources::of($this);
    }

    public function isReady(): bool
    {
        return $this->status === ServerStatus::Ready;
    }

    public function ensureReady(): void
    {
        if ($this->isReady()) {
            return;
        }

        throw ValidationException::withMessages(['server' => "{$this->name} is not ready yet."]);
    }

    public function isBeingCreated(): bool
    {
        return $this->status === ServerStatus::Creating;
    }

    public function isBeingSetUp(): bool
    {
        return $this->status === ServerStatus::Provisioning || $this->serverLogs()->running()->exists();
    }

    public function markPending(): void
    {
        $this->update(['status' => ServerStatus::Pending]);
    }

    public function markProvisioning(): void
    {
        $this->update(['status' => ServerStatus::Provisioning]);
    }

    public function markProvisioned(): void
    {
        $this->update(['status' => ServerStatus::Ready, 'ssh_password' => null]);
    }

    public function markFailed(): void
    {
        $this->update(['status' => ServerStatus::Failed]);
    }

    public function failSetup(): void
    {
        if ($this->status->isSettingUp()) {
            $this->markFailed();
        }
    }

    public function recordLaunch(ProviderServer $instance): void
    {
        $this->update([
            'public_ip' => $instance->publicIp,
            'private_ip' => $this->privateIpAfterLaunchOf($instance),
            'status' => ServerStatus::Pending,
        ]);
    }

    private function privateIpAfterLaunchOf(ProviderServer $instance): ?string
    {
        if ($this->wireguardNetwork() !== null) {
            return $this->private_ip;
        }

        if ($this->network_id === null) {
            return null;
        }

        return $instance->privateIp;
    }

    public function isInDatabase(): bool
    {
        return $this->databaseNodes()->exists();
    }

    public function ensureDatabasesSpareIt(): void
    {
        $this->databaseNodes()->with('database')->get()->each(function (DatabaseNode $node): void {
            $node->database->ensureIdle();

            if ($node->isPrimary() && $node->database->hasStandby()) {
                throw ValidationException::withMessages(['server' => "{$this->name} is the primary of {$node->database->name}, fail over first."]);
            }
        });
    }

    public function inDatabaseError(string $key): ValidationException
    {
        return ValidationException::withMessages([$key => "{$this->name} is part of a database, remove it from the database first."]);
    }

    public function ensureMovable(): void
    {
        $node = $this->databaseNodes()->with('database')->first();

        if ($node === null) {
            return;
        }

        throw ValidationException::withMessages(['id' => "Move database {$node->database->name} instead; its servers move with it."]);
    }

    public function ensureDeletable(): void
    {
        if ($this->isInDatabase()) {
            throw $this->inDatabaseError('server');
        }
    }

    public function joinWireguard(string $publicKey): void
    {
        $this->update(['wireguard_public_key' => $publicKey, 'network_status' => NetworkMemberStatus::Joining, 'network_issue' => null]);
    }

    public function leaveWireguard(): void
    {
        $this->update(['wireguard_public_key' => null, 'network_status' => null, 'network_issue' => null, 'network_checked_at' => null]);
    }

    public function trustsHostKey(string $fingerprint): bool
    {
        if ($this->host_key_fingerprint === null) {
            $this->update(['host_key_fingerprint' => $fingerprint]);

            return true;
        }

        return $this->host_key_fingerprint === $fingerprint;
    }

    public function flagDatabasesForRetune(NodeResources $before): void
    {
        $after = $this->resources();

        if ($after->cpus !== $before->cpus || $after->memoryMb !== $before->memoryMb) {
            Database::query()->whereIn('id', $this->databaseNodes()->select('database_id'))->update(['retune_needed_at' => now()]);
        }
    }

    public function wireguardPeerOn(Network $network): ?WireguardPeer
    {
        if ($this->wireguard_public_key === null || $this->private_ip === null || $this->public_ip === null) {
            return null;
        }

        return new WireguardPeer($this->wireguard_public_key, $this->private_ip, $this->public_ip, "{$this->public_ip}:{$network->listen_port}");
    }

    /**
     * @param  Builder<Server>  $query
     */
    #[Scope]
    protected function matching(Builder $query, string $term): void
    {
        $query->where(fn (Builder $query) => $query->whereLike('name', "%{$term}%")->orWhereLike('public_ip', "%{$term}%")->orWhereLike('private_ip', "%{$term}%"));
    }

    /**
     * @param  Builder<Server>  $query
     */
    #[Scope]
    protected function ready(Builder $query): void
    {
        $query->where('status', ServerStatus::Ready);
    }

    /**
     * @param  Builder<Server>  $query
     */
    #[Scope]
    protected function withoutDatabase(Builder $query): void
    {
        $query->whereDoesntHave('databaseNodes');
    }

    /**
     * @param  Builder<Server>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->ready()->withoutDatabase();
    }

    /**
     * @param  Builder<Server>  $query
     */
    #[Scope]
    protected function usingPrivateAddress(Builder $query, string $networkId, string $address): void
    {
        $query->where('network_id', $networkId)->where('private_ip', $address);
    }

    /**
     * @param  Builder<Server>  $query
     */
    #[Scope]
    protected function withWireguardKey(Builder $query): void
    {
        $query->whereNotNull('wireguard_public_key');
    }

    public function recordNetworkHealth(NetworkMemberStatus $status, ?string $issue = null): void
    {
        $this->update(['network_status' => $status, 'network_issue' => $issue, 'network_checked_at' => now()]);
    }

    public function wireguardNetwork(): ?Network
    {
        return $this->network?->isWireguard() ? $this->network : null;
    }

    public function canJoinCluster(): bool
    {
        return $this->memory_mb === null || $this->memory_mb >= Database::CLUSTER_MEMORY_MB;
    }

    public function sharesPrivateNetworkWith(Server $other): bool
    {
        return $this->network_id !== null && $this->network_id === $other->network_id;
    }

    /**
     * @return HasMany<ServerLog, $this>
     */
    public function serverLogs(): HasMany
    {
        return $this->hasMany(ServerLog::class);
    }

    public function startLog(string $name): ServerLog
    {
        return $this->serverLogs()->create(['name' => $name]);
    }

    public function logFailure(string $name, string $message, ?Database $database = null): void
    {
        $this->serverLogs()->create(['name' => $name, 'database_id' => $database?->id, 'status' => ServerLogStatus::Failed, 'output' => ServerLog::noteOf($message)]);
    }

    public function ssh(?Database $database = null): Ssh
    {
        return app(Ssh::class, ['server' => $this, 'database' => $database]);
    }

    protected function casts(): array
    {
        return [
            'ssh_password' => 'encrypted',
            'status' => ServerStatus::class,
            'image' => OperatingSystem::class,
            'network_status' => NetworkMemberStatus::class,
            'network_checked_at' => 'datetime',
            'ssh_port' => 'integer',
            'cpus' => 'integer',
            'memory_mb' => 'integer',
            'disk_gb' => 'integer',
            'interface_subnets' => AsCollection::class,
        ];
    }
}
