<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DatabaseNode;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Server
 */
class ServerResource extends JsonResource
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
            'status' => $this->status->value,
            'public_ip' => $this->public_ip,
            'private_ip' => $this->private_ip,
            'network_id' => $this->network_id,
            'network_status' => $this->network_status?->value,
            'server_provider_id' => $this->server_provider_id,
            'region' => $this->region,
            'plan' => $this->plan,
            'ssh_port' => $this->ssh_port,
            'ssh_user' => $this->ssh_user,
            'os' => $this->os,
            'cpus' => $this->cpus,
            'memory_mb' => $this->memory_mb,
            'disk_gb' => $this->disk_gb,
            'host_key_fingerprint' => $this->host_key_fingerprint,
            'created_at' => $this->created_at?->toIso8601String(),
            'database' => $this->whenLoaded('databaseNodes', fn (): ?array => $this->databaseNodes->map(fn (DatabaseNode $node): array => [
                'id' => $node->database->id,
                'name' => $node->database->name,
                'node_id' => $node->id,
                'kind' => $node->kind->value,
            ])->first()),
        ];
    }
}
