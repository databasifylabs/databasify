<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

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
            'password_encryption' => ($this->password_encryption ?? $this->database->passwordEncryptionFor(null))?->value,
            'is_default' => $this->isDefault(),
        ];
    }
}
