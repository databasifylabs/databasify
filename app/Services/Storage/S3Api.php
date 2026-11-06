<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\DTOs\Storage\S3Bucket;
use App\Exceptions\Providers\ProviderRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class S3Api
{
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 20;

    public function __construct(private S3Bucket $bucket) {}

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $key, string $body = '', array $query = [], array $headers = []): Response
    {
        $url = $this->urlWith($key, $query);
        $signature = new S3Signature($this->bucket, $method, $url, $body, now());

        try {
            $response = $this->requestWith($signature, $body, $headers)->send($method, $url);
        } catch (ConnectionException) {
            throw ProviderRequestFailed::unreachable($this->bucket->host());
        }

        if ($response->failed()) {
            throw ProviderRequestFailed::from($this->bucket->host(), $response->status(), $this->reasonIn($response->body()));
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function urlWith(string $key, array $query): string
    {
        $url = $this->bucket->urlOf($key);

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function requestWith(S3Signature $signature, string $body, array $headers): PendingRequest
    {
        return Http::withHeaders(array_merge($signature->headers(), $headers))
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS)
            ->when($body !== '', fn (PendingRequest $request): PendingRequest => $request->withBody($body, 'application/octet-stream'));
    }

    private function reasonIn(string $body): ?string
    {
        preg_match('#<Code>([^<]*)</Code>#', $body, $code);
        preg_match('#<Message>([^<]*)</Message>#', $body, $message);
        $reason = implode(': ', array_filter([$code[1] ?? '', $message[1] ?? ''], fn (string $part): bool => $part !== ''));

        return $reason === '' ? null : $reason;
    }
}
