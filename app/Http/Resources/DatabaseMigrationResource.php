<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DatabaseMigration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseMigration
 */
class DatabaseMigrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'source' => $this->source->address(),
            'detail' => $this->detail,
            'lag_seconds' => $this->lag_seconds,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'cleanup_failed' => $this->cleanupFailed(),
        ];
    }
}
