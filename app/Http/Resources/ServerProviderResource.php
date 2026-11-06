<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ServerProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServerProvider
 */
class ServerProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->describedType(),
            'servers_count' => $this->whenCounted('servers'),
            'networks_count' => $this->whenCounted('networks'),
        ];
    }
}
