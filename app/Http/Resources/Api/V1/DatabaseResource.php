<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Database;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Database
 */
class DatabaseResource extends JsonResource
{
    public static function accepted(Database $database): JsonResponse
    {
        return (new self($database->refresh()))->response()->setStatusCode(202);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hostnames = $this->hostnames();
        $restore = $this->settings->restore;

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'engine' => $this->engine,
            'version_line' => $this->version_line,
            'engine_version' => $this->image->release,
            'available_version' => $this->available_image?->release,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'health' => $this->nodes->health(),
            'port' => $this->clientPort(),
            'pool_port' => $this->facts()->ports->pool,
            'dns_zone_id' => $this->dns_zone_id,
            'hostnames' => $hostnames === null ? null : [
                'primary' => $hostnames->primary->public,
                'private' => $hostnames->primary->private,
                'replica' => $hostnames->replica->public,
                'private_replica' => $hostnames->replica->private,
            ],
            'ca_certificate' => $this->clientCaCertificate(),
            'persistence' => $this->settings->persistence->value,
            'config' => (object) $this->settings->config,
            'maintenance' => $this->maintenanceWindow()->toArray(now()),
            'point_in_time_recovery' => $this->pointInTimeRecovery === null ? null : new PointInTimeRecoveryResource($this->pointInTimeRecovery),
            'restored_from' => $restore === null ? null : ['database' => $restore->database, 'target' => $restore->target->toIso8601String()],
            'nodes' => DatabaseNodeResource::collection($this->nodes),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
