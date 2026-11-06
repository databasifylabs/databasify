<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Recovery\ArchiveLocation;
use App\DTOs\Recovery\ArchiveStatus;
use App\DTOs\Recovery\RecoverySchedule;
use App\DTOs\Recovery\RecoverySource;
use App\DTOs\Recovery\StoredArchive;
use App\Enums\Recovery\ArchiveHealth;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PointInTimeRecoveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $database_id
 * @property string $storage_provider_id
 * @property string $path
 * @property int $retention_days
 * @property int $backup_hour
 * @property ArchiveHealth $health
 * @property int $backups_count
 * @property CarbonImmutable|null $earliest_restorable_at
 * @property CarbonImmutable|null $latest_restorable_at
 * @property CarbonImmutable|null $last_backup_at
 * @property string|null $problem
 * @property CarbonImmutable|null $checked_at
 * @property-read Database $database
 * @property-read StorageProvider $storageProvider
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['storage_provider_id', 'path', 'retention_days', 'backup_hour', 'health', 'backups_count', 'earliest_restorable_at', 'latest_restorable_at', 'last_backup_at', 'problem', 'checked_at'])]
#[UseFactory(PointInTimeRecoveryFactory::class)]
class PointInTimeRecovery extends Model
{
    /** @use HasFactory<PointInTimeRecoveryFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * @return BelongsTo<StorageProvider, $this>
     */
    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(StorageProvider::class);
    }

    public function location(): ArchiveLocation
    {
        return new ArchiveLocation($this->storageProvider->bucket(), $this->path);
    }

    public function storedArchive(): StoredArchive
    {
        return new StoredArchive($this->storage_provider_id, $this->path);
    }

    public function canRestoreTo(CarbonInterface $moment): bool
    {
        return $this->earliest_restorable_at !== null
            && $this->latest_restorable_at !== null
            && $moment->betweenIncluded($this->earliest_restorable_at, $this->latest_restorable_at);
    }

    public function sourceAt(CarbonInterface $target): RecoverySource
    {
        return new RecoverySource($this->database->name, $this->storage_provider_id, $this->path, CarbonImmutable::instance($target)->utc());
    }

    public function recordStatus(ArchiveStatus $status): void
    {
        $this->update($status->toRow(now()));
    }

    public function reschedule(RecoverySchedule $schedule): void
    {
        $this->update($schedule->toRow());
    }

    public function mayReachAfterRefresh(CarbonInterface $target): bool
    {
        if ($this->canRestoreTo($target) || $target->isFuture()) {
            return false;
        }

        if ($this->earliest_restorable_at !== null && $target->lessThan($this->earliest_restorable_at)) {
            return false;
        }

        return ! $this->database->isBusy();
    }

    public function restorableWindowMessage(): string
    {
        return $this->earliest_restorable_at === null || $this->latest_restorable_at === null
            ? 'The archive has no complete base backup yet.'
            : "Pick a time between {$this->earliest_restorable_at->utc()->format('Y-m-d H:i:s')} and {$this->latest_restorable_at->utc()->format('Y-m-d H:i:s')} UTC.";
    }

    protected function casts(): array
    {
        return [
            'health' => ArchiveHealth::class,
            'retention_days' => 'integer',
            'backup_hour' => 'integer',
            'backups_count' => 'integer',
            'earliest_restorable_at' => 'datetime',
            'latest_restorable_at' => 'datetime',
            'last_backup_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
