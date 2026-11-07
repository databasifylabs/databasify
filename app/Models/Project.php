<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\Projects\ProjectNotDeletable;
use App\Models\Concerns\OrdersByName;
use App\Observers\ProjectObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name'])]
#[UseFactory(ProjectFactory::class)]
#[ObservedBy(ProjectObserver::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, OrdersByName;

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class)->withoutProject();
    }

    /**
     * @return HasMany<Database, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class)->withoutProject();
    }

    /**
     * @return HasMany<Network, $this>
     */
    public function networks(): HasMany
    {
        return $this->hasMany(Network::class)->withoutProject();
    }

    /**
     * @return HasMany<ServerProvider, $this>
     */
    public function serverProviders(): HasMany
    {
        return $this->hasMany(ServerProvider::class)->withoutProject();
    }

    /**
     * @return HasMany<DnsProvider, $this>
     */
    public function dnsProviders(): HasMany
    {
        return $this->hasMany(DnsProvider::class)->withoutProject();
    }

    /**
     * @return HasMany<StorageProvider, $this>
     */
    public function storageProviders(): HasMany
    {
        return $this->hasMany(StorageProvider::class)->withoutProject();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function lastVisitors(): HasMany
    {
        return $this->hasMany(User::class, 'last_project_id');
    }

    public function isEmpty(): bool
    {
        return collect([$this->servers(), $this->databases(), $this->networks(), $this->serverProviders(), $this->dnsProviders(), $this->storageProviders()])
            ->doesntContain(fn (HasMany $resources): bool => $resources->exists());
    }

    public function ensureDeletable(): void
    {
        if (self::query()->whereKeyNot($this->id)->doesntExist()) {
            throw ProjectNotDeletable::lastOne();
        }

        if (! $this->isEmpty()) {
            throw ProjectNotDeletable::stillInUse($this->name);
        }
    }
}
