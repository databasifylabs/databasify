<?php

declare(strict_types=1);

namespace App\Models;

use App\DnsProviders\Contracts\DnsDriver;
use App\DnsProviders\Drivers\AbstractDnsDriver;
use App\Exceptions\Providers\UnknownProvider;
use App\Models\Concerns\DescribesProviderType;
use App\Models\Concerns\OrdersByName;
use Carbon\CarbonImmutable;
use Database\Factories\DnsProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Validation\ValidationException;

/**
 * @property string $id
 * @property string $name
 * @property string $type
 * @property array<string, string> $credentials
 * @property-read Collection<int, DnsZone> $zones
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'type', 'credentials'])]
#[Hidden(['credentials'])]
#[UseFactory(DnsProviderFactory::class)]
class DnsProvider extends Model
{
    use DescribesProviderType, OrdersByName;

    /** @use HasFactory<DnsProviderFactory> */
    use HasFactory, HasUuids;

    /**
     * @return HasMany<DnsZone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(DnsZone::class)->orderBy('name');
    }

    /**
     * @return HasManyThrough<Database, DnsZone, $this>
     */
    public function databases(): HasManyThrough
    {
        return $this->hasManyThrough(Database::class, DnsZone::class);
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_values(array_filter(array_keys(config()->array('dns-provider.providers', [])), is_string(...)));
    }

    /**
     * @return array<string, DnsDriver>
     */
    public static function providers(): array
    {
        return array_combine(self::types(), array_map(self::providerFor(...), self::types()));
    }

    public static function providerFor(string $type): DnsDriver
    {
        return (new self(['type' => $type]))->provider();
    }

    public function provider(): DnsDriver
    {
        $handler = config("dns-provider.providers.{$this->type}.handler");

        if (! is_string($handler) || ! is_subclass_of($handler, AbstractDnsDriver::class)) {
            throw UnknownProvider::named($this->type);
        }

        return new $handler($this);
    }

    public function ensureDeletable(): void
    {
        $database = $this->databases()->first();

        if ($database !== null) {
            throw ValidationException::withMessages(['provider' => "{$database->name} uses a domain from {$this->name}, pick another domain for it first."]);
        }
    }

    /**
     * @param  list<string>  $visible
     */
    public function ensureZonesInUseAmong(array $visible): void
    {
        $hidden = collect(array_filter($this->zones()->has('databases')->whereNotIn('name', $visible)->pluck('name')->all(), is_string(...)));

        if ($hidden->isNotEmpty()) {
            throw ValidationException::withMessages(['credentials' => "The new credentials cannot see {$hidden->join(', ', ' and ')}, which databases use. Give them access to those domains first."]);
        }
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }
}
