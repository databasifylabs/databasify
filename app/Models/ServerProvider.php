<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Projects\Movable;
use App\Exceptions\Providers\UnknownProvider;
use App\Models\Concerns\BelongsToProject;
use App\Models\Concerns\DescribesProviderType;
use App\Models\Concerns\MovesBetweenProjects;
use App\Models\Concerns\OrdersByName;
use App\ServerProviders\Contracts\ServerDriver;
use App\ServerProviders\Drivers\AbstractServerDriver;
use Carbon\CarbonImmutable;
use Database\Factories\ServerProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $type
 * @property array<string, string> $credentials
 * @property-read int|null $servers_count
 * @property-read int|null $networks_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['project_id', 'name', 'type', 'credentials'])]
#[Hidden(['credentials'])]
#[UseFactory(ServerProviderFactory::class)]
class ServerProvider extends Model implements Movable
{
    /** @use HasFactory<ServerProviderFactory> */
    use BelongsToProject, HasFactory, HasUuids, MovesBetweenProjects;

    use DescribesProviderType, OrdersByName;

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class)->withoutProject();
    }

    /**
     * @return HasMany<Network, $this>
     */
    public function networks(): HasMany
    {
        return $this->hasMany(Network::class)->withoutProject();
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_values(array_filter(array_keys(config()->array('server-provider.providers', [])), is_string(...)));
    }

    /**
     * @return array<string, ServerDriver>
     */
    public static function providers(): array
    {
        return array_combine(self::types(), array_map(self::providerFor(...), self::types()));
    }

    public static function providerFor(string $type): ServerDriver
    {
        return (new self(['type' => $type]))->provider();
    }

    public function provider(): ServerDriver
    {
        $handler = config("server-provider.providers.{$this->type}.handler");

        if (! is_string($handler) || ! is_subclass_of($handler, AbstractServerDriver::class)) {
            throw UnknownProvider::named($this->type);
        }

        return new $handler($this);
    }

    public function ensureDeletable(): void
    {
        $dependents = collect([
            'server' => $this->servers()->count(),
            'network' => $this->networks()->count(),
        ])->filter()->map(fn (int $count, string $noun): string => "{$count} ".str($noun)->plural($count));

        if ($dependents->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages(['provider' => "{$this->name} still has {$dependents->join(' and ')} in Databasify. Delete them first."]);
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }
}
