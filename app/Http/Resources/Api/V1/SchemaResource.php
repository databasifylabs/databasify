<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Schema
 */
class SchemaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->isDefault(),
        ];
    }
}
