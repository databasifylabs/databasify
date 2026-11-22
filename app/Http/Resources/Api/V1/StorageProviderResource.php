<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PointInTimeRecovery;
use App\Models\StorageProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StorageProvider
 */
class StorageProviderResource extends JsonResource
{
    public static function loaded(StorageProvider $provider): self
    {
        return new self($provider->load('recoveries'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'path_prefix' => $this->path_prefix,
            'path_style' => $this->path_style,
            'access_key_id' => $this->bucket()->accessKeyId,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'database_ids' => $this->whenLoaded('recoveries', fn (): array => $this->recoveries->map(fn (PointInTimeRecovery $recovery): string => $recovery->database_id)->all()),
        ];
    }
}
