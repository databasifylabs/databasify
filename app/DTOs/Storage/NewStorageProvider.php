<?php

declare(strict_types=1);

namespace App\DTOs\Storage;

use Carbon\CarbonInterface;

final readonly class NewStorageProvider
{
    public function __construct(
        public string $name,
        public S3Bucket $bucket,
    ) {}

    /**
     * @return array{name: string, endpoint: string, region: string, bucket: string, path_prefix: string, path_style: bool, credentials: array{access_key_id: string, secret_access_key: string}}
     */
    public function toRow(): array
    {
        return array_merge(
            ['name' => $this->name],
            $this->bucket->location(),
            ['credentials' => $this->bucket->credentials()],
        );
    }

    /**
     * @return array{name: string, endpoint: string, region: string, bucket: string, path_prefix: string, path_style: bool, credentials: array{access_key_id: string, secret_access_key: string}, verified_at: CarbonInterface}
     */
    public function toVerifiedRow(CarbonInterface $at): array
    {
        return array_merge($this->toRow(), ['verified_at' => $at]);
    }
}
