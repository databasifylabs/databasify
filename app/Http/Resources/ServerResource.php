<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Servers\OperatingSystem;
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
            'name' => $this->name,
            'public_ip' => $this->public_ip,
            'private_ip' => $this->private_ip,
            'network' => $this->whenLoaded('network', fn (): ?NetworkResource => $this->network === null ? null : new NetworkResource($this->network)),
            'network_id' => $this->network_id,
            'network_status' => $this->network_status?->value,
            'network_issue' => $this->network_issue,
            'network_checked_at' => $this->network_checked_at?->toIso8601String(),
            'provider' => $this->whenLoaded('serverProvider', fn (): ?ServerProviderResource => $this->serverProvider === null ? null : new ServerProviderResource($this->serverProvider)),
            'region' => $this->region,
            'plan' => $this->plan,
            'ssh_port' => $this->ssh_port,
            'ssh_user' => $this->ssh_user,
            'status' => $this->status->value,
            'os' => $this->os,
            'distribution' => $this->distribution(),
            'distribution_icon' => OperatingSystem::iconOf($this->distribution()),
            'cpus' => $this->cpus,
            'memory_mb' => $this->memory_mb,
            'disk_gb' => $this->disk_gb,
            'host_key_fingerprint' => $this->host_key_fingerprint,
            'created_at' => $this->created_at?->toIso8601String(),
            'database' => $this->whenLoaded('databaseNodes', fn (): ?array => $this->membershipOf($this->databaseNodes->first())),
        ];
    }

    /**
     * @return array{id: string, name: string, engine: string, engine_icon: string|null, kind: string, node_id: string}|null
     */
    private function membershipOf(?DatabaseNode $node): ?array
    {
        if ($node === null) {
            return null;
        }

        return [
            'id' => $node->database->id,
            'name' => $node->database->name,
            'engine' => $node->database->engine,
            'engine_icon' => $node->database->provider()->icon(),
            'kind' => $node->kind->value,
            'node_id' => $node->id,
        ];
    }
}
