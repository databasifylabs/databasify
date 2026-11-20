<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ServerLog;
use Illuminate\Http\Request;

/**
 * @mixin ServerLog
 */
class ServerLogOutputResource extends ServerLogResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'output' => $this->output ?? '',
        ];
    }
}
