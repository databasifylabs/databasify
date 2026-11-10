<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Acme\AcmeCertificate;
use App\DTOs\Databases\IssuedCertificate;
use App\Models\Concerns\OrdersByName;
use Carbon\CarbonImmutable;
use Database\Factories\DnsZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $dns_provider_id
 * @property string $name
 * @property string $external_id
 * @property string|null $certificate
 * @property string|null $certificate_key
 * @property-read DnsProvider $provider
 * @property-read int|null $databases_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['dns_provider_id', 'name', 'external_id', 'certificate', 'certificate_key'])]
#[Hidden(['certificate', 'certificate_key'])]
#[UseFactory(DnsZoneFactory::class)]
class DnsZone extends Model
{
    use HasUuids, OrdersByName;

    public const string DATABASE_SUBDOMAIN = 'db';

    /** @use HasFactory<DnsZoneFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<DnsProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(DnsProvider::class, 'dns_provider_id')->withoutProject();
    }

    /**
     * @return HasMany<Database, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class)->withoutProject();
    }

    public function databaseDomain(): string
    {
        return self::DATABASE_SUBDOMAIN.".{$this->name}";
    }

    public function hostFor(string $label): string
    {
        return "{$label}.{$this->databaseDomain()}";
    }

    public function wildcardName(): string
    {
        return "*.{$this->databaseDomain()}";
    }

    public function hasCurrentCertificate(): bool
    {
        return IssuedCertificate::fromPem($this->certificate ?? '')?->isCurrentFor([$this->wildcardName()]) ?? false;
    }

    public function certificateFingerprint(): ?string
    {
        return IssuedCertificate::fingerprintOf($this->certificate ?? '');
    }

    public function certificateBundle(): ?string
    {
        if ($this->certificate === null || $this->certificate_key === null) {
            return null;
        }

        return rtrim($this->certificate)."\n".rtrim($this->certificate_key)."\n";
    }

    /**
     * @return array<string, string>
     */
    public function servedCertificate(): array
    {
        $bundle = $this->certificateBundle();

        return $bundle === null ? [] : ['server.pem' => $bundle];
    }

    public function storeCertificate(AcmeCertificate $certificate): void
    {
        $this->update(['certificate' => $certificate->chain, 'certificate_key' => $certificate->key]);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inCurrentProject(Builder $query): void
    {
        $query->whereIn('dns_provider_id', DnsProvider::query()->select('id'));
    }

    protected function casts(): array
    {
        return ['certificate_key' => 'encrypted'];
    }
}
