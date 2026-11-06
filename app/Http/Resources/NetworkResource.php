<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Network;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Network
 */
class NetworkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'cidr' => $this->cidr,
            'region' => $this->region,
            'provider' => $this->whenLoaded('serverProvider', fn (): ?string => $this->serverProvider?->name),
            'provider_label' => $this->whenLoaded('serverProvider', fn (): ?string => $this->serverProvider?->label()),
            'provider_type' => $this->whenLoaded('serverProvider', fn (): ?string => $this->serverProvider?->type),
            'provider_icon' => $this->whenLoaded('serverProvider', fn (): ?string => $this->serverProvider?->icon()),
            'servers_count' => $this->whenCounted('servers'),
            'connected_servers_count' => $this->whenCounted('connectedServers'),
        ];
    }
}
