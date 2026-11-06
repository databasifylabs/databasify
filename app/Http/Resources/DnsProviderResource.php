<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DnsProvider;
use App\Models\DnsZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DnsProvider
 */
class DnsProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->describedType(),
            'zones' => $this->zones->map(fn (DnsZone $zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'databases_count' => $zone->databases_count ?? 0,
            ])->all(),
        ];
    }
}
