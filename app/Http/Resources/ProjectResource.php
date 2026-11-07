<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'servers_count' => $this->whenCounted('servers'),
            'databases_count' => $this->whenCounted('databases'),
            'networks_count' => $this->whenCounted('networks'),
            'server_providers_count' => $this->whenCounted('serverProviders'),
            'dns_providers_count' => $this->whenCounted('dnsProviders'),
            'storage_providers_count' => $this->whenCounted('storageProviders'),
        ];
    }
}
