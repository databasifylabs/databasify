<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ConnectionPool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConnectionPool
 */
class DatabasePoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'schema' => $this->schema,
            'mode' => $this->mode->value,
            'size' => $this->size,
        ];
    }
}
