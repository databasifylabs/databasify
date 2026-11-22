<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Access\AccessArea;
use App\Models\ApiKey;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApiKey
 */
class ApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'levels' => collect(AccessArea::cases())->mapWithKeys(fn (AccessArea $area): array => [$area->value => $area->levelIn($this->scopes)->value])->all(),
            'all_projects' => $this->all_projects,
            'projects' => $this->projects->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])->all(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expired' => $this->expires_at?->isPast() ?? false,
        ];
    }
}
