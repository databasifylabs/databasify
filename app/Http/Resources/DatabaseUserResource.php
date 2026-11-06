<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DatabaseUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseUser
 */
class DatabaseUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'password' => $this->password,
            'password_encryption' => $this->password_encryption?->value,
            'is_default' => $this->isDefault(),
        ];
    }
}
