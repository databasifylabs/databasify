<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Exceptions\Providers\ProviderRequestFailed;
use App\Services\Http\Payload;
use App\Services\Http\TransientFailure;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class ProviderHttp
{
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 20;

    /**
     * @param  Closure(Payload): ?string  $errorMessage
     */
    public static function for(string $provider, string $baseUrl, string $token, Closure $errorMessage, bool $allowMissing = false): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS)
            ->withOptions(['provider' => $provider])
            ->throwIf(fn (Response $response): bool => ! ($allowMissing && $response->notFound()))
            ->throw(fn (Response $response) => throw ProviderRequestFailed::from($provider, $response->status(), $errorMessage(Payload::of($response->json()))));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public static function get(PendingRequest $request, string $path, array $query = []): Response
    {
        return self::send(TransientFailure::retrying($request), fn (PendingRequest $pending): Response => $pending->get($path, $query));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function post(PendingRequest $request, string $path, array $body): Response
    {
        return self::send($request, fn (PendingRequest $pending): Response => $pending->post($path, $body));
    }

    public static function delete(PendingRequest $request, string $path): Response
    {
        return self::send($request, fn (PendingRequest $pending): Response => $pending->delete($path));
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private static function send(PendingRequest $request, Closure $call): Response
    {
        try {
            return $call($request);
        } catch (ConnectionException) {
            throw ProviderRequestFailed::unreachable(self::providerOf($request));
        }
    }

    private static function providerOf(PendingRequest $request): string
    {
        $provider = $request->getOptions()['provider'] ?? null;

        return is_string($provider) ? $provider : 'The provider';
    }
}
