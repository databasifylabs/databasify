<?php

declare(strict_types=1);

namespace App\DTOs\Storage;

use App\Models\StorageProvider;

final readonly class S3Bucket
{
    private const int HTTPS_PORT = 443;

    public function __construct(
        public string $endpoint,
        public string $region,
        public string $bucket,
        public string $pathPrefix,
        public bool $pathStyle,
        public string $accessKeyId,
        public string $secretAccessKey,
    ) {}

    public static function fromProvider(StorageProvider $provider): self
    {
        return new self(
            endpoint: $provider->endpoint,
            region: $provider->region,
            bucket: $provider->bucket,
            pathPrefix: $provider->path_prefix,
            pathStyle: $provider->path_style,
            accessKeyId: $provider->credentials['access_key_id'] ?? '',
            secretAccessKey: $provider->credentials['secret_access_key'] ?? '',
        );
    }

    /**
     * @return array{access_key_id: string, secret_access_key: string}
     */
    public function credentials(): array
    {
        return ['access_key_id' => $this->accessKeyId, 'secret_access_key' => $this->secretAccessKey];
    }

    /**
     * @return array{endpoint: string, region: string, bucket: string, path_prefix: string, path_style: bool}
     */
    public function location(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'path_prefix' => $this->pathPrefix,
            'path_style' => $this->pathStyle,
        ];
    }

    public function endpointHost(): string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);

        return is_string($host) ? $host : $this->endpoint;
    }

    public function port(): int
    {
        $port = parse_url($this->endpoint, PHP_URL_PORT);

        return is_int($port) ? $port : self::HTTPS_PORT;
    }

    public function host(): string
    {
        $host = $this->pathStyle ? $this->endpointHost() : "{$this->bucket}.{$this->endpointHost()}";

        return $this->port() === self::HTTPS_PORT ? $host : "{$host}:{$this->port()}";
    }

    public function under(string $path): string
    {
        return ltrim("{$this->pathPrefix}/{$path}", '/');
    }

    public function urlOf(string $key): string
    {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $key)));

        return $this->pathStyle ? "https://{$this->host()}/{$this->bucket}/{$encoded}" : "https://{$this->host()}/{$encoded}";
    }
}
