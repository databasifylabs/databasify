<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Access\AccessArea;
use App\Enums\Mcp\AiAgent;
use App\Models\OAuthClient;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin OAuthClient
 *
 * @property OAuthClient $resource
 */
class ConnectedAppResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $agent = AiAgent::forClient($this->resource);
        $lastUsedAt = $this->tokens_max_last_used_at;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'agent' => $agent?->value,
            'icon' => $agent?->icon(),
            'redirect_host' => $this->redirectHosts(),
            'levels' => collect(AccessArea::cases())->mapWithKeys(fn (AccessArea $area): array => [$area->value => $area->levelIn($this->scopes ?? [])->value])->all(),
            'all_projects' => $this->all_projects,
            'projects' => $this->projects->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])->all(),
            'connected_at' => $this->approved_at?->toIso8601String(),
            'last_used_at' => is_string($lastUsedAt) ? Carbon::parse($lastUsedAt)->toIso8601String() : null,
        ];
    }
}
