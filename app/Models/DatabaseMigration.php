<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsMigrationPosition;
use App\Casts\AsMigrationSource;
use App\DTOs\Migrations\MigrationPosition;
use App\DTOs\Migrations\MigrationSource;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use App\Services\Ssh\Ssh;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $database_id
 * @property string|null $database_node_id
 * @property MigrationMode $mode
 * @property MigrationStatus $status
 * @property MigrationSource $source
 * @property MigrationPosition $position
 * @property string|null $detail
 * @property int|null $lag_seconds
 * @property int $unhealthy_checks
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $cleanup_failed_at
 * @property-read Database $database
 * @property-read DatabaseNode|null $node
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['database_node_id', 'mode', 'status', 'source', 'position', 'detail', 'lag_seconds', 'unhealthy_checks', 'started_at', 'finished_at', 'cleanup_failed_at'])]
#[Hidden(['source'])]
class DatabaseMigration extends Model
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
     * @return BelongsTo<DatabaseNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(DatabaseNode::class, 'database_node_id');
    }

    public function host(): DatabaseNode
    {
        return $this->node ?? $this->database->primaryOrSeed();
    }

    public function ssh(): Ssh
    {
        return $this->host()->server->ssh($this->database);
    }

    public function finishIfActive(MigrationStatus $status, ?string $detail = null): bool
    {
        return $this->updateWhileIn(MigrationStatus::active(), ['status' => $status, 'detail' => $detail ?? $this->detail, 'finished_at' => now(), 'cleanup_failed_at' => now()]);
    }

    public function markCleanedUp(): void
    {
        $this->update(['cleanup_failed_at' => null]);
    }

    public function cleanupFailed(): bool
    {
        return $this->cleanup_failed_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isSyncing(): bool
    {
        return $this->status === MigrationStatus::Syncing;
    }

    public function isOneTime(): bool
    {
        return $this->mode === MigrationMode::OneTime;
    }

    public function hostLostPrimary(): bool
    {
        $host = $this->host();

        return $host->database->nodes()->whereKeyNot($host->id)->primary()->exists();
    }

    public function markCopying(): void
    {
        $this->updateWhileIn(MigrationStatus::active(), ['detail' => 'Copying from the source.']);
    }

    public function claimSyncing(): bool
    {
        return $this->updateWhileIn([MigrationStatus::Copying], ['status' => MigrationStatus::Syncing, 'detail' => 'Copied, now following changes on the source.', 'unhealthy_checks' => 0]);
    }

    public function recordProgress(string $detail): void
    {
        $this->updateWhileIn(MigrationStatus::active(), ['detail' => $detail, 'unhealthy_checks' => 0]);
    }

    public function recordSyncProgress(string $detail, ?int $lagSeconds): void
    {
        $this->updateWhileIn(MigrationStatus::active(), ['detail' => $detail, 'lag_seconds' => $lagSeconds, 'unhealthy_checks' => 0]);
    }

    public function recordStall(string $detail): void
    {
        $this->updateWhileIn(MigrationStatus::active(), ['detail' => $detail, 'unhealthy_checks' => DB::raw('unhealthy_checks + 1')]);
    }

    public function stallsReach(int $limit): bool
    {
        return $this->unhealthy_checks + 1 >= $limit;
    }

    /**
     * @param  list<MigrationStatus>  $statuses
     * @param  array<string, mixed>  $attributes
     */
    private function updateWhileIn(array $statuses, array $attributes): bool
    {
        $updated = self::query()->whereKey($this->getKey())->whereIn('status', $statuses)->update($attributes) === 1;
        $this->refresh();

        return $updated;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', MigrationStatus::active());
    }

    protected function casts(): array
    {
        return [
            'mode' => MigrationMode::class,
            'status' => MigrationStatus::class,
            'source' => AsMigrationSource::class,
            'position' => AsMigrationPosition::class,
            'lag_seconds' => 'integer',
            'unhealthy_checks' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cleanup_failed_at' => 'datetime',
        ];
    }
}
