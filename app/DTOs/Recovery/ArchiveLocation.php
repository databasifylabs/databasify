<?php

declare(strict_types=1);

namespace App\DTOs\Recovery;

use App\DTOs\Storage\S3Bucket;

final readonly class ArchiveLocation
{
    public function __construct(
        public S3Bucket $bucket,
        public string $path,
    ) {}

    public function rcloneRemote(): string
    {
        return "archive:{$this->bucket->bucket}/{$this->path}";
    }

    /**
     * @return array<string, string>
     */
    public function rcloneEnvironment(): array
    {
        return [
            'RCLONE_CONFIG' => '',
            'RCLONE_CONFIG_ARCHIVE_TYPE' => 's3',
            'RCLONE_CONFIG_ARCHIVE_PROVIDER' => 'Other',
            'RCLONE_CONFIG_ARCHIVE_ENDPOINT' => $this->bucket->endpoint,
            'RCLONE_CONFIG_ARCHIVE_REGION' => $this->bucket->region,
            'RCLONE_CONFIG_ARCHIVE_ACCESS_KEY_ID' => $this->bucket->accessKeyId,
            'RCLONE_CONFIG_ARCHIVE_SECRET_ACCESS_KEY' => $this->bucket->secretAccessKey,
            'RCLONE_CONFIG_ARCHIVE_FORCE_PATH_STYLE' => $this->bucket->pathStyle ? 'true' : 'false',
            'RCLONE_CONFIG_ARCHIVE_NO_CHECK_BUCKET' => 'true',
            'ARCHIVE' => $this->rcloneRemote(),
        ];
    }

    /**
     * @return array{type: string, s3: array{endpointUrl: string, region: string, bucket: string, prefix: string, forcePathStyle: bool, credentials: array{access-key-id: string, secret-access-key: string}}}
     */
    public function pbmStorage(): array
    {
        return [
            'type' => 's3',
            's3' => [
                'endpointUrl' => $this->bucket->endpoint,
                'region' => $this->bucket->region,
                'bucket' => $this->bucket->bucket,
                'prefix' => $this->path,
                'forcePathStyle' => $this->bucket->pathStyle,
                'credentials' => ['access-key-id' => $this->bucket->accessKeyId, 'secret-access-key' => $this->bucket->secretAccessKey],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function pgbackrestRepository(): array
    {
        return array_filter([
            'repo1-type' => 's3',
            'repo1-s3-endpoint' => $this->bucket->endpointHost(),
            'repo1-storage-port' => $this->bucket->port() === 443 ? null : (string) $this->bucket->port(),
            'repo1-s3-bucket' => $this->bucket->bucket,
            'repo1-s3-region' => $this->bucket->region,
            'repo1-s3-key' => $this->bucket->accessKeyId,
            'repo1-s3-key-secret' => $this->bucket->secretAccessKey,
            'repo1-s3-uri-style' => $this->bucket->pathStyle ? 'path' : 'host',
            'repo1-path' => "/{$this->path}",
        ], fn (?string $value): bool => $value !== null);
    }
}
