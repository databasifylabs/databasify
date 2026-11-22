<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DnsProvider;
use App\Models\DnsZone;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DnsProvider
 */
class DnsProviderResource extends JsonResource
{
    public static function loaded(DnsProvider $provider): self
    {
        return new self($provider->load(['zones' => fn (HasMany $zones) => $zones->withCount('databases')]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label(),
            'zones' => $this->whenLoaded('zones', fn (): array => $this->zones->map(fn (DnsZone $zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'databases_count' => $zone->databases_count ?? 0,
            ])->all()),
        ];
    }
}
