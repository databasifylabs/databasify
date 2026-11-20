<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Model $resource
 */
class MovedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource->getMorphClass(),
            'id' => $this->resource->getKey(),
            'project_id' => $this->resource->getAttribute('project_id'),
        ];
    }
}
