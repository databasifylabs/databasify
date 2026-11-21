<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\Api\SystemInfo;
use App\Models\Plugin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property SystemInfo $resource
 */
class SystemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->resource->version,
            'latest_version' => $this->resource->latestVersion,
            'update_available' => $this->resource->updateAvailable,
            'plugins' => $this->resource->plugins->map(fn (Plugin $plugin): array => [
                'id' => $plugin->id,
                'name' => $plugin->name,
                'version' => $plugin->version,
                'enabled' => $plugin->enabled,
            ])->all(),
        ];
    }
}
