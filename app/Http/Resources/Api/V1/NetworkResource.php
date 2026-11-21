<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

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
            'project_id' => $this->project_id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'cidr' => $this->cidr,
            'region' => $this->region,
            'server_provider_id' => $this->server_provider_id,
            'servers_count' => $this->whenCounted('servers'),
        ];
    }
}
