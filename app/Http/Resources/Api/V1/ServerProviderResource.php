<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ServerProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServerProvider
 */
class ServerProviderResource extends JsonResource
{
    public static function loaded(ServerProvider $provider): self
    {
        return new self($provider->loadCount(['servers', 'networks']));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label(),
            'servers_count' => $this->whenCounted('servers'),
            'networks_count' => $this->whenCounted('networks'),
        ];
    }
}
