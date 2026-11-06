<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\DTOs\Storage\S3Bucket;
use Carbon\CarbonInterface;

final readonly class S3Signature
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    private const string SERVICE = 's3';

    public function __construct(
        private S3Bucket $bucket,
        private string $method,
        private string $url,
        private string $payload,
        private CarbonInterface $signedAt,
    ) {}

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'x-amz-content-sha256' => hash('sha256', $this->payload),
            'x-amz-date' => $this->timestamp(),
        ];

        return array_merge($headers, ['Authorization' => $this->authorizationFor($headers)]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function authorizationFor(array $headers): string
    {
        $signed = array_merge(['host' => $this->bucket->host()], $headers);
        ksort($signed);
        $names = implode(';', array_keys($signed));
        $signature = $this->signatureOf($this->canonicalRequestWith($signed, $names));

        return self::ALGORITHM." Credential={$this->bucket->accessKeyId}/{$this->scope()}, SignedHeaders={$names}, Signature={$signature}";
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function canonicalRequestWith(array $headers, string $names): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return implode("\n", [$this->method, is_string($path) ? $path : '/', $this->canonicalQuery(), $this->canonicalHeadersOf($headers), $names, $headers['x-amz-content-sha256']]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function canonicalHeadersOf(array $headers): string
    {
        return implode('', array_map(fn (string $name, string $value): string => "{$name}:".trim($value)."\n", array_keys($headers), $headers));
    }

    private function canonicalQuery(): string
    {
        $query = parse_url($this->url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return '';
        }

        return collect(explode('&', $query))
            ->map($this->encodedPartsOf(...))
            ->sortBy(fn (array $pair): string => $pair[0])
            ->map(fn (array $pair): string => "{$pair[0]}={$pair[1]}")
            ->implode('&');
    }

    /**
     * @return list<string>
     */
    private function encodedPartsOf(string $pair): array
    {
        return array_map(fn (string $part): string => rawurlencode(rawurldecode($part)), array_pad(explode('=', $pair, 2), 2, ''));
    }

    private function signatureOf(string $canonicalRequest): string
    {
        return hash_hmac('sha256', implode("\n", [self::ALGORITHM, $this->timestamp(), $this->scope(), hash('sha256', $canonicalRequest)]), $this->signingKey());
    }

    private function signingKey(): string
    {
        return array_reduce(
            [$this->bucket->region, self::SERVICE, 'aws4_request'],
            fn (string $key, string $part): string => hash_hmac('sha256', $part, $key, true),
            hash_hmac('sha256', $this->day(), "AWS4{$this->bucket->secretAccessKey}", true),
        );
    }

    private function scope(): string
    {
        return "{$this->day()}/{$this->bucket->region}/".self::SERVICE.'/aws4_request';
    }

    private function timestamp(): string
    {
        return $this->signedAt->copy()->utc()->format('Ymd\THis\Z');
    }

    private function day(): string
    {
        return $this->signedAt->copy()->utc()->format('Ymd');
    }
}
