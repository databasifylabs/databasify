<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NodeAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NodeAlert
 */
class DatabaseAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->database_node_id,
            'node_name' => $this->node->displayName(),
            'metric' => $this->metric->value,
            'label' => $this->metric->label(),
            'opened_at' => $this->opened_at->toIso8601String(),
        ];
    }
}
