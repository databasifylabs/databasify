<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\Images\ImageRegistry;
use App\DTOs\Images\BearerChallenge;
use App\DTOs\Images\ImageLabels;
use App\DTOs\Images\RegistryRepository;
use App\Exceptions\Images\ImageUnavailable;
use App\Services\Http\Payload;
use App\Services\Http\TransientFailure;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class OciRegistry implements ImageRegistry
{
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int TIMEOUT_SECONDS = 15;

    private const int PAGE_SIZE = 1000;

    private const int UNAUTHORIZED = 401;

    private const string PLATFORM = 'linux/amd64';

    private const array MANIFEST_TYPES = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.oci.image.manifest.v1+json',
        'application/vnd.docker.distribution.manifest.v2+json',
    ];

    public function tagsOf(string $repository): array
    {
        $address = RegistryRepository::parse($repository);

        return $this->tagsFrom($this->clientFor($address), $address, $address->tagsUrl(self::PAGE_SIZE));
    }

    public function digestOf(string $repository, string $tag): string
    {
        $address = RegistryRepository::parse($repository);
        $client = $this->clientFor($address)->accept(implode(', ', self::MANIFEST_TYPES));
        $response = $this->successful(fn (): Response => $client->get($address->manifestUrl($tag)), $address);

        return $response->header('Docker-Content-Digest') ?: 'sha256:'.hash('sha256', $response->body());
    }

    public function labelsOf(string $repository, string $digest): ImageLabels
    {
        $address = RegistryRepository::parse($repository);
        $client = $this->clientFor($address)->accept(implode(', ', self::MANIFEST_TYPES));
        $manifest = $this->manifestOf($client, $address, $digest);
        $config = $this->successful(fn (): Response => $client->get($address->blobUrl($manifest->string('config.digest'))), $address);

        return ImageLabels::from($config->json('config.Labels'));
    }

    private function manifestOf(PendingRequest $client, RegistryRepository $address, string $digest): Payload
    {
        $manifest = Payload::of($this->successful(fn (): Response => $client->get($address->manifestUrl($digest)), $address)->json());
        $platforms = collect($manifest->items('manifests'));
        $platform = $platforms->first(fn (Payload $entry): bool => "{$entry->string('platform.os')}/{$entry->string('platform.architecture')}" === self::PLATFORM)
            ?? $platforms->first();

        return $platform === null ? $manifest : $this->manifestOf($client, $address, $platform->string('digest'));
    }

    /**
     * @return list<string>
     */
    private function tagsFrom(PendingRequest $client, RegistryRepository $address, string $url): array
    {
        $response = $this->successful(fn (): Response => $client->get($url), $address);
        $tags = Payload::of($response->json())->strings('tags');
        $next = $address->nextPageIn($response->header('Link'));

        return $next === null ? $tags : array_merge($tags, $this->tagsFrom($client, $address, $next));
    }

    private function clientFor(RegistryRepository $address): PendingRequest
    {
        $probe = $this->attempt(fn (): Response => $this->client()->get("{$address->baseUrl()}/v2/"), $address);

        if ($probe->status() !== self::UNAUTHORIZED) {
            return $this->client();
        }

        $challenge = BearerChallenge::from($probe->header('WWW-Authenticate'))
            ?? throw ImageUnavailable::answered($address->repository, self::UNAUTHORIZED);

        return $this->client()->withToken($this->tokenFor($challenge, $address));
    }

    private function tokenFor(BearerChallenge $challenge, RegistryRepository $address): string
    {
        $response = $this->successful(fn (): Response => $this->client()->acceptJson()->get($challenge->realm, $challenge->queryFor($address->pullScope())), $address);
        $body = Payload::of($response->json());

        return $body->stringOrNull('token') ?? $body->stringOrNull('access_token') ?? throw ImageUnavailable::answered($address->repository, $response->status());
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function successful(Closure $request, RegistryRepository $address): Response
    {
        $response = $this->attempt($request, $address);

        if ($response->failed()) {
            throw ImageUnavailable::answered($address->repository, $response->status());
        }

        return $response;
    }

    /**
     * @param  Closure(): Response  $request
     */
    private function attempt(Closure $request, RegistryRepository $address): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw ImageUnavailable::unreachable($address->repository);
        }
    }

    private function client(): PendingRequest
    {
        return TransientFailure::retrying(Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS));
    }
}
