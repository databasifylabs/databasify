<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DnsZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DnsZone
 */
class DnsZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'database_domain' => $this->databaseDomain(),
            'provider' => $this->provider->name,
        ];
    }
}
