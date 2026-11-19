<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsCredentials;
use App\Models\Concerns\BelongsToProject;
use App\Observers\FileBackupDestinationObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property string $database_id
 * @property string $name
 * @property string $protocol
 * @property string $host
 * @property int $port
 * @property string $username
 * @property string $path
 * @property string|null $host_key
 * @property array<string, string> $credentials
 * @property bool $enabled
 * @property int $backup_hour
 * @property CarbonImmutable|null $next_backup_at
 * @property CarbonImmutable|null $verified_at
 * @property-read Database $database
 */
#[Hidden(['credentials'])]
#[ObservedBy(FileBackupDestinationObserver::class)]
class FileBackupDestination extends Model
{
    use BelongsToProject, HasUuids;

    /** @return BelongsTo<Database, $this> */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class)->withoutProject();
    }

    /** @return HasMany<FileBackupRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(FileBackupRun::class);
    }

    public function nextBackup(): CarbonImmutable
    {
        $next = now()->utc()->startOfDay()->addHours($this->backup_hour);

        return $next->isFuture() ? $next : $next->addDay();
    }

    protected function casts(): array
    {
        return ['credentials' => AsCredentials::class, 'enabled' => 'boolean', 'port' => 'integer', 'backup_hour' => 'integer', 'verified_at' => 'datetime', 'next_backup_at' => 'datetime'];
    }
}
