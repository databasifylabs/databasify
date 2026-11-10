<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Databases\DatabaseEndpoints;
use App\DTOs\Databases\Endpoint;
use App\DTOs\Databases\EndpointPair;
use App\DTOs\Databases\NodeResources;
use App\DTOs\Databases\NodeStatus;
use App\DTOs\Dns\HostPair;
use App\Enums\Databases\NodeKind;
use App\Enums\Databases\NodeRole;
use App\Enums\Databases\ResourceMetric;
use App\Models\Collections\DatabaseNodeCollection;
use App\Observers\DatabaseNodeObserver;
use App\Services\Ssh\Ssh;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $database_id
 * @property string $server_id
 * @property string|null $name
 * @property int $position
 * @property NodeKind $kind
 * @property NodeRole $role
 * @property string|null $engine_version
 * @property-read Database $database
 * @property-read Server $server
 * @property-read Collection<int, NodeAlert> $openAlerts
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['server_id', 'name', 'position', 'kind', 'role', 'engine_version'])]
#[CollectedBy(DatabaseNodeCollection::class)]
#[ObservedBy(DatabaseNodeObserver::class)]
class DatabaseNode extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return HasMany<NodeAlert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(NodeAlert::class);
    }

    /**
     * @return HasMany<NodeAlert, $this>
     */
    public function openAlerts(): HasMany
    {
        return $this->alerts()->open();
    }

    /**
     * @return HasMany<DatabaseMigration, $this>
     */
    public function migrations(): HasMany
    {
        return $this->hasMany(DatabaseMigration::class);
    }

    /**
     * @return HasMany<NodeMetricSample, $this>
     */
    public function metricSamples(): HasMany
    {
        return $this->hasMany(NodeMetricSample::class);
    }

    /**
     * @return Collection<int, NodeMetricSample>
     */
    public function latestSamples(int $count): Collection
    {
        return $this->metricSamples()->latest('sampled_at')->limit($count)->get();
    }

    public function openAlertFor(ResourceMetric $metric): ?NodeAlert
    {
        return $this->openAlerts->first(fn (NodeAlert $alert): bool => $alert->metric === $metric);
    }

    public function raiseAlert(ResourceMetric $metric): NodeAlert
    {
        return $this->alerts()->create(['metric' => $metric, 'opened_at' => now()]);
    }

    public function isMember(): bool
    {
        return $this->kind === NodeKind::Member;
    }

    public function isReadOnly(): bool
    {
        return $this->kind === NodeKind::ReadOnly;
    }

    public function isPrimary(): bool
    {
        return $this->role === NodeRole::Primary;
    }

    public function isReplica(): bool
    {
        return $this->role === NodeRole::Replica;
    }

    public function recordStatus(NodeStatus $status): void
    {
        $this->update(['role' => $status->role, 'engine_version' => $status->version ?? $this->engine_version]);
    }

    public function rename(string $name): void
    {
        $this->update(['name' => $name]);
        $this->database->forgetNodeCertificates($this);
    }

    public function isSeed(): bool
    {
        return $this->position === 0;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function members(Builder $query): void
    {
        $query->where('kind', NodeKind::Member);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function readOnly(Builder $query): void
    {
        $query->where('kind', NodeKind::ReadOnly);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inCurrentProject(Builder $query): void
    {
        $query->whereHas('database');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function primary(Builder $query): void
    {
        $query->where('role', NodeRole::Primary);
    }

    public function endpoints(): DatabaseEndpoints
    {
        $pair = $this->endpointPair($this->hostnames(), $this->database->clientPort());

        return new DatabaseEndpoints($pair->public, $pair->vpc);
    }

    public function endpointPair(?HostPair $hostnames, int $port): EndpointPair
    {
        return new EndpointPair(
            new Endpoint($hostnames->public ?? $this->server->address(), $port),
            new Endpoint($hostnames->private ?? $this->privateIp(), $port),
        );
    }

    public function resources(): NodeResources
    {
        return $this->server->resources();
    }

    public function privateIp(): string
    {
        return $this->server->private_ip ?? $this->server->address();
    }

    public function displayName(): string
    {
        return $this->name ?? $this->server->name;
    }

    /**
     * @return list<string>
     */
    public function certificateNames(): array
    {
        return array_values(array_unique(array_merge($this->hostnames()?->all() ?? [], [$this->server->address(), $this->privateIp()])));
    }

    /**
     * @return list<string>
     */
    public function ownCertificateNames(): array
    {
        $records = $this->database->serviceRecords();

        return array_values(array_unique([
            ...($this->database->hostnames()?->all() ?? []),
            ...($records === null ? [] : [$records->memberHostOf($this)]),
            ...$this->certificateNames(),
        ]));
    }

    public function hostnames(): ?HostPair
    {
        $zone = $this->database->dnsZone;

        return $zone === null ? null : $this->hostnamesUnder($zone);
    }

    public function hostnamesUnder(DnsZone $zone): ?HostPair
    {
        return $this->name === null || ! $this->isReadOnly() ? null : HostPair::under($zone, $this->name);
    }

    protected function casts(): array
    {
        return [
            'kind' => NodeKind::class,
            'role' => NodeRole::class,
            'position' => 'integer',
        ];
    }

    public function ssh(): Ssh
    {
        return $this->server->ssh($this->database);
    }
}
