<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ServerLog;
use Illuminate\Http\Request;

/**
 * @mixin ServerLog
 */
class ServerLogResource extends ServerLogListResource
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
