<?php

declare(strict_types=1);

namespace App\DTOs\Images;

final readonly class RegistryRepository
{
    private const string DOCKER_HUB = 'docker.io';

    private const string DOCKER_HUB_API = 'registry-1.docker.io';

    public function __construct(
        public string $repository,
        public string $host,
        public string $name,
    ) {}

    public static function parse(string $repository): self
    {
        [$host, $name] = array_pad(explode('/', $repository, 2), 2, '');

        return new self($repository, $host === self::DOCKER_HUB ? self::DOCKER_HUB_API : $host, $name);
    }

    public function baseUrl(): string
    {
        return "https://{$this->host}";
    }

    public function pullScope(): string
    {
        return "repository:{$this->name}:pull";
    }

    public function tagsUrl(int $pageSize): string
    {
        return "{$this->baseUrl()}/v2/{$this->name}/tags/list?n={$pageSize}";
    }

    public function manifestUrl(string $reference): string
    {
        return "{$this->baseUrl()}/v2/{$this->name}/manifests/{$reference}";
    }

    public function blobUrl(string $digest): string
    {
        return "{$this->baseUrl()}/v2/{$this->name}/blobs/{$digest}";
    }

    public function nextPageIn(string $link): ?string
    {
        if (preg_match('/<([^>]+)>\s*;\s*rel="?next"?/', $link, $match) !== 1) {
            return null;
        }

        return str_starts_with($match[1], '/') ? $this->baseUrl().$match[1] : $match[1];
    }
}
