<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DatabaseUser;
use Illuminate\Http\Request;

/**
 * @mixin DatabaseUser
 */
class DatabaseUserCredentialsResource extends DatabaseUserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'password' => $this->password,
        ];
    }
}
