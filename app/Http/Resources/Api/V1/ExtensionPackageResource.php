<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\Databases\ExtensionPackage;
use App\DTOs\Databases\PackageFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExtensionPackage
 */
class ExtensionPackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'request' => $this->request,
            'repository' => $this->repository === null ? null : preg_replace('#https://[^/\s@]+@#', 'https://', $this->repository),
            'packages' => $this->packages,
            'extensions' => $this->extensionNames(),
            'architectures' => array_values(array_unique(array_map(fn (PackageFile $file): string => $file->architecture, $this->files))),
        ];
    }
}
