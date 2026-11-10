<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Database;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Database
 */
class DatabaseListResource extends JsonResource
{
    public const array RELATIONS = ['dnsZone', 'nodes', 'pointInTimeRecovery.storageProvider'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $engine = $this->engine();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'engine_label' => $engine->label,
            'engine_icon' => $engine->icon,
            'engine_version' => $this->image_release,
            'available_version' => $this->available_image_release,
            'available_update_refreshes_image' => $this->availableUpdateRefreshesImage(),
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'operation' => $this->operation,
            'created_at' => $this->created_at?->toIso8601String(),
            'point_in_time_recovery' => PointInTimeRecoveryResource::make($this->pointInTimeRecovery),
            'nodes' => DatabaseNodeResource::collection($this->nodes),
        ];
    }
}
