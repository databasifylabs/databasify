<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TrustedSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TrustedSource
 */
class TrustedSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cidr' => $this->cidr,
            'label' => $this->label,
        ];
    }
}
