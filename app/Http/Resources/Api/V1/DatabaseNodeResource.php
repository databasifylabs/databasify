<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DatabaseNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseNode
 */
class DatabaseNodeResource extends JsonResource
{
    public static function accepted(DatabaseNode $node): JsonResponse
    {
        return (new self($node->refresh()))->response()->setStatusCode(202);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'database_id' => $this->database_id,
            'name' => $this->displayName(),
            'kind' => $this->kind->value,
            'role' => $this->role->value,
            'status' => $this->status?->value,
            'server_id' => $this->server_id,
            'private_ip' => $this->privateIp(),
            'public_ip' => $this->server->public_ip,
            'engine_version' => $this->engine_version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
