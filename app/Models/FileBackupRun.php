<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $file_backup_destination_id
 * @property string $status
 * @property string|null $remote_file
 * @property string|null $problem
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property-read FileBackupDestination $destination
 */
class FileBackupRun extends Model
{
    use HasUuids;

    /** @return BelongsTo<FileBackupDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(FileBackupDestination::class, 'file_backup_destination_id')->withoutProject();
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
