<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PointInTimeRecovery;
use App\Models\StorageProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StorageProvider
 */
class StorageProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'path_prefix' => $this->path_prefix,
            'path_style' => $this->path_style,
            'access_key_id' => $this->bucket()->accessKeyId,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'databases' => $this->whenLoaded('recoveries', fn (): array => $this->recoveries->map(fn (PointInTimeRecovery $recovery): array => [
                'id' => $recovery->database->id,
                'name' => $recovery->database->name,
            ])->all()),
        ];
    }
}
