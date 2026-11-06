<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DatabaseNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseNode
 */
class DatabaseNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'name' => $this->displayName(),
            'server_name' => $this->server->name,
            'public_ip' => $this->server->public_ip,
            'private_ip' => $this->privateIp(),
            'kind' => $this->kind->value,
            'role' => $this->role->value,
            'is_seed' => $this->isSeed(),
            'engine_version' => $this->engine_version,
            'domain' => $this->hostnames()?->public,
            'private_domain' => $this->hostnames()?->private,
            'cpus' => $this->server->cpus,
            'memory_mb' => $this->server->memory_mb,
            'disk_gb' => $this->server->disk_gb,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
