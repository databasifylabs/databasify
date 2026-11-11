<?php

declare(strict_types=1);

use App\Exceptions\Images\ImageUnavailable;
use App\Services\Images\OciRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeGhcr(array $routes): void
{
    Http::fake([
        'ghcr.io/v2/' => Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401, ['WWW-Authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:user/image:pull"']),
        'ghcr.io/token*' => Http::response(['token' => 'anonymous-token']),
        ...$routes,
    ]);
}

it('lists every tag across pages with an anonymous pull token', function () {
    fakeGhcr([
        'ghcr.io/v2/databasifylabs/databasify-postgres/tags/list?n=1000' => Http::response(['tags' => ['17.11', '18.6']], 200, ['Link' => '</v2/databasifylabs/databasify-postgres/tags/list?last=18.6&n=1000>; rel="next"']),
        'ghcr.io/v2/databasifylabs/databasify-postgres/tags/list?last=18.6&n=1000' => Http::response(['tags' => ['18.7', 'latest']]),
    ]);

    expect(resolve(OciRegistry::class)->tagsOf('ghcr.io/databasifylabs/databasify-postgres'))->toBe(['17.11', '18.6', '18.7', 'latest']);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://ghcr.io/token?')
        && $request['service'] === 'ghcr.io' && $request['scope'] === 'repository:databasifylabs/databasify-postgres:pull');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'last=18.6') && $request->hasHeader('Authorization', 'Bearer anonymous-token'));
});

it('resolves a tag to the digest of its multi-platform index', function () {
    $digest = 'sha256:'.str_repeat('a', 64);
    fakeGhcr([
        'ghcr.io/v2/databasifylabs/databasify-postgres/manifests/18.7' => Http::response(['schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.index.v1+json', 'manifests' => []], 200, ['Docker-Content-Digest' => $digest]),
    ]);

    expect(resolve(OciRegistry::class)->digestOf('ghcr.io/databasifylabs/databasify-postgres', '18.7'))->toBe($digest);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/manifests/18.7')
        && str_contains($request->header('Accept')[0] ?? '', 'application/vnd.oci.image.index.v1+json')
        && str_contains($request->header('Accept')[0] ?? '', 'application/vnd.docker.distribution.manifest.list.v2+json'));
});

it('hashes the manifest itself when the registry sends no digest header', function () {
    $manifest = '{"schemaVersion":2}';
    fakeGhcr(['ghcr.io/v2/databasifylabs/databasify-postgres/manifests/18.7' => Http::response($manifest)]);

    expect(resolve(OciRegistry::class)->digestOf('ghcr.io/databasifylabs/databasify-postgres', '18.7'))->toBe('sha256:'.hash('sha256', $manifest));
});

it('talks to registries that need no token directly and maps docker hub to its api host', function () {
    Http::fake([
        'registry-1.docker.io/v2/' => Http::response([]),
        'registry-1.docker.io/v2/library/redis/tags/list*' => Http::response(['tags' => ['8.2.1']]),
    ]);

    expect(resolve(OciRegistry::class)->tagsOf('docker.io/library/redis'))->toBe(['8.2.1']);
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization'));
});

it('reports a missing tag or repository', function () {
    fakeGhcr(['ghcr.io/v2/databasifylabs/databasify-postgres/*' => Http::response(['errors' => [['code' => 'MANIFEST_UNKNOWN']]], 404)]);

    expect(fn () => resolve(OciRegistry::class)->digestOf('ghcr.io/databasifylabs/databasify-postgres', '18.99'))->toThrow(ImageUnavailable::class, 'The image registry answered 404 for ghcr.io/databasifylabs/databasify-postgres.')
        ->and(fn () => resolve(OciRegistry::class)->tagsOf('ghcr.io/databasifylabs/databasify-postgres'))->toThrow(ImageUnavailable::class, 'answered 404');
});

it('reports a registry it cannot reach', function () {
    Http::fake(['ghcr.io/*' => Http::failedConnection()]);

    expect(fn () => resolve(OciRegistry::class)->tagsOf('ghcr.io/databasifylabs/databasify-postgres'))->toThrow(ImageUnavailable::class, 'The image registry for ghcr.io/databasifylabs/databasify-postgres could not be reached.');
});

it('retries a registry that fails with a server error and then answers', function () {
    Http::fake([
        'ghcr.io/v2/' => Http::response([]),
        'ghcr.io/v2/databasifylabs/databasify-postgres/tags/list*' => Http::sequence()->push('', 503)->push(['tags' => ['18.7']]),
    ]);

    expect(resolve(OciRegistry::class)->tagsOf('ghcr.io/databasifylabs/databasify-postgres'))->toBe(['18.7']);
});

it('reads the sdk label from the linux/amd64 image config of an index', function () {
    $index = 'sha256:'.str_repeat('a', 64);
    $amd64 = 'sha256:'.str_repeat('b', 64);
    $config = 'sha256:'.str_repeat('c', 64);
    fakeGhcr([
        "ghcr.io/v2/databasifylabs/databasify-postgres/manifests/{$index}" => Http::response(['manifests' => [
            ['digest' => 'sha256:'.str_repeat('d', 64), 'platform' => ['os' => 'linux', 'architecture' => 'arm64']],
            ['digest' => $amd64, 'platform' => ['os' => 'linux', 'architecture' => 'amd64']],
        ]]),
        "ghcr.io/v2/databasifylabs/databasify-postgres/manifests/{$amd64}" => Http::response(['config' => ['digest' => $config]]),
        "ghcr.io/v2/databasifylabs/databasify-postgres/blobs/{$config}" => Http::response(['config' => ['Labels' => ['io.databasify.engine' => 'postgres', 'io.databasify.sdk' => '0.1.2']]]),
    ]);

    expect(resolve(OciRegistry::class)->labelsOf('ghcr.io/databasifylabs/databasify-postgres', $index)->sdk)->toBe('0.1.2');
});

it('reads no sdk from an image config without the label', function () {
    $manifest = 'sha256:'.str_repeat('b', 64);
    $config = 'sha256:'.str_repeat('c', 64);
    fakeGhcr([
        "ghcr.io/v2/databasifylabs/databasify-postgres/manifests/{$manifest}" => Http::response(['config' => ['digest' => $config]]),
        "ghcr.io/v2/databasifylabs/databasify-postgres/blobs/{$config}" => Http::response(['config' => ['Labels' => null]]),
    ]);

    expect(resolve(OciRegistry::class)->labelsOf('ghcr.io/databasifylabs/databasify-postgres', $manifest)->sdk)->toBeNull();
});

it('skips attestation manifests when an index has no linux/amd64 image', function () {
    $index = 'sha256:'.str_repeat('a', 64);
    $arm64 = 'sha256:'.str_repeat('b', 64);
    $config = 'sha256:'.str_repeat('c', 64);
    fakeGhcr([
        "ghcr.io/v2/databasifylabs/databasify-postgres/manifests/{$index}" => Http::response(['manifests' => [
            ['digest' => 'sha256:'.str_repeat('d', 64), 'platform' => ['os' => 'unknown', 'architecture' => 'unknown']],
            ['digest' => $arm64, 'platform' => ['os' => 'linux', 'architecture' => 'arm64']],
        ]]),
        "ghcr.io/v2/databasifylabs/databasify-postgres/manifests/{$arm64}" => Http::response(['config' => ['digest' => $config]]),
        "ghcr.io/v2/databasifylabs/databasify-postgres/blobs/{$config}" => Http::response(['config' => ['Labels' => ['io.databasify.sdk' => '0.1.2']]]),
        'ghcr.io/v2/databasifylabs/databasify-postgres/*' => Http::response(['config' => ['digest' => 'sha256:'.str_repeat('e', 64)]]),
    ]);

    expect(resolve(OciRegistry::class)->labelsOf('ghcr.io/databasifylabs/databasify-postgres', $index)->sdk)->toBe('0.1.2');
});
