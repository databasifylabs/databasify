<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Engines\Data\AvailableExtension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AvailableExtension
 */
class AvailableExtensionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->entry->name,
            'label' => $this->entry->label,
            'description' => $this->entry->description,
            'group' => $this->entry->group->value,
            'group_label' => $this->entry->group->label(),
            'default_version' => $this->defaultVersion,
            'needs_restart' => $this->entry->needsRestart(),
        ];
    }
}
