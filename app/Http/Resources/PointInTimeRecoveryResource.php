<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PointInTimeRecovery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PointInTimeRecovery
 */
class PointInTimeRecoveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'storage' => [
                'id' => $this->storageProvider->id,
                'name' => $this->storageProvider->name,
                'bucket' => $this->storageProvider->bucket,
            ],
            'path' => $this->path,
            'retention_days' => $this->retention_days,
            'backup_hour' => $this->backup_hour,
            'health' => $this->health->value,
            'health_label' => $this->health->label(),
            'backups_count' => $this->backups_count,
            'earliest_restorable_at' => $this->earliest_restorable_at?->toIso8601String(),
            'latest_restorable_at' => $this->latest_restorable_at?->toIso8601String(),
            'last_backup_at' => $this->last_backup_at?->toIso8601String(),
            'problem' => $this->problem,
            'checked_at' => $this->checked_at?->toIso8601String(),
            'enabled_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
