<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Engines\Data\InstalledExtension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InstalledExtension
 */
class InstalledExtensionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'default_version' => $this->defaultVersion,
            'has_update' => $this->hasUpdate(),
        ];
    }
}
