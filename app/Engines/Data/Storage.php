<?php

declare(strict_types=1);

namespace App\Engines\Data;

use App\DTOs\Storage\S3Bucket;
use App\Models\StorageProvider;

final readonly class Storage
{
    public function __construct(
        public string $endpoint,
        public string $bucket,
        public string $region,
        public string $accessKey,
        public string $secretKey,
        public bool $pathStyle,
        public string $path,
    ) {}

    public static function at(StorageProvider $provider, string $path): self
    {
        $bucket = S3Bucket::fromProvider($provider);

        return new self($bucket->endpoint, $bucket->bucket, $bucket->region, $bucket->accessKeyId, $bucket->secretAccessKey, $bucket->pathStyle, $path);
    }

    /**
     * @return array{endpoint: string, bucket: string, region: string, accessKey: string, secretKey: string, pathStyle: bool, verifyTls: bool, path: string}
     */
    public function toPayload(): array
    {
        return ['endpoint' => $this->endpoint, 'bucket' => $this->bucket, 'region' => $this->region, 'accessKey' => $this->accessKey, 'secretKey' => $this->secretKey, 'pathStyle' => $this->pathStyle, 'verifyTls' => true, 'path' => $this->path];
    }
}
