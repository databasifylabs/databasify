<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ServerLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServerLog
 */
class ServerLogListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'exit_code' => $this->exit_code,
            'server' => ['id' => $this->server->id, 'name' => $this->server->name],
            'database' => $this->database === null ? null : ['id' => $this->database->id, 'name' => $this->database->name],
            'group' => $this->group_id === null ? null : ['id' => $this->group_id, 'name' => $this->group_name],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
