<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Passkey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Passkey
 */
class PasskeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'authenticator' => $this->authenticator,
            'created_at_diff' => $this->created_at?->diffForHumans(),
            'last_used_at_diff' => $this->last_used_at?->diffForHumans(),
        ];
    }
}
