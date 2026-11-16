<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Plugin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plugin
 */
class PluginResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'author' => $this->author,
            'author_url' => $this->author_url,
            'community' => $this->isCommunity(),
            'version' => $this->version,
            'source' => $this->source,
            'icon' => $this->iconUrl(),
            'uploaded' => $this->wasUploaded(),
            'enabled' => $this->enabled,
            'error' => $this->error,
        ];
    }
}
