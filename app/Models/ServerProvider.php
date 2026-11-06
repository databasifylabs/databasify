<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\Providers\UnknownProvider;
use App\Models\Concerns\DescribesProviderType;
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
 * @property string $name
 * @property string $type
 * @property array<string, string> $credentials
 * @property-read int|null $servers_count
 * @property-read int|null $networks_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'type', 'credentials'])]
#[Hidden(['credentials'])]
#[UseFactory(ServerProviderFactory::class)]
class ServerProvider extends Model
{
    use DescribesProviderType, OrdersByName;

    /** @use HasFactory<ServerProviderFactory> */
    use HasFactory, HasUuids;

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * @return HasMany<Network, $this>
     */
    public function networks(): HasMany
    {
        return $this->hasMany(Network::class);
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
        $servers = $this->servers()->count();

        if ($servers > 0) {
            throw ValidationException::withMessages(['provider' => "{$this->name} still has {$servers} ".str('server')->plural($servers).' in Databasify. Delete them first.']);
        }
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }
}
