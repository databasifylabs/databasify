<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Projects\Movable;
use App\DTOs\Storage\S3Bucket;
use App\Models\Concerns\BelongsToProject;
use App\Models\Concerns\MovesBetweenProjects;
use App\Models\Concerns\OrdersByName;
use App\Services\Storage\S3;
use Carbon\CarbonImmutable;
use Database\Factories\StorageProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $endpoint
 * @property string $region
 * @property string $bucket
 * @property string $path_prefix
 * @property bool $path_style
 * @property array<string, string> $credentials
 * @property CarbonImmutable|null $verified_at
 * @property-read Collection<int, PointInTimeRecovery> $recoveries
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['project_id', 'name', 'endpoint', 'region', 'bucket', 'path_prefix', 'path_style', 'credentials', 'verified_at'])]
#[Hidden(['credentials'])]
#[UseFactory(StorageProviderFactory::class)]
class StorageProvider extends Model implements Movable
{
    /** @use HasFactory<StorageProviderFactory> */
    use BelongsToProject, HasFactory, HasUuids, MovesBetweenProjects;

    use OrdersByName;

    /**
     * @return HasMany<PointInTimeRecovery, $this>
     */
    public function recoveries(): HasMany
    {
        return $this->hasMany(PointInTimeRecovery::class);
    }

    /**
     * @return HasManyThrough<Database, PointInTimeRecovery, $this>
     */
    public function databases(): HasManyThrough
    {
        return $this->hasManyThrough(Database::class, PointInTimeRecovery::class, 'storage_provider_id', 'id', 'id', 'database_id')->withoutProject();
    }

    public function bucket(): S3Bucket
    {
        return S3Bucket::fromProvider($this);
    }

    public function s3(): S3
    {
        return S3::make($this->bucket());
    }

    public function markVerified(): void
    {
        $this->update(['verified_at' => now()]);
    }

    /**
     * @return SupportCollection<int, Database>
     */
    public function archivingDatabases(): SupportCollection
    {
        return $this->databases()->get()->toBase();
    }

    public function ensureDeletable(): void
    {
        $database = $this->databases()->first();

        if ($database === null) {
            return;
        }

        throw ValidationException::withMessages(['provider' => "{$database->name} archives to {$this->name}, turn off its point-in-time recovery first."]);
    }

    public function ensureLocationKeptFor(S3Bucket $bucket): void
    {
        $first = $this->archivingDatabases()->first();

        if ($first === null || $this->bucket()->location() === $bucket->location()) {
            return;
        }

        throw ValidationException::withMessages(['storage' => "{$first->name} archives to {$this->name}, so its endpoint, bucket and path stay as they are. Only the name and keys can change."]);
    }

    protected function casts(): array
    {
        return [
            'path_style' => 'boolean',
            'credentials' => 'encrypted:array',
            'verified_at' => 'datetime',
        ];
    }
}
