<?php

declare(strict_types=1);

use App\Actions\Updates\CheckForRelease;
use App\DTOs\Updates\Release;
use App\Queries\Updates\LatestReleaseQuery;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, string>
 */
function releaseFeed(string $version = '0.2.0'): array
{
    return ['version' => $version, 'published_at' => '2026-11-20T08:00:00Z', 'url' => "https://github.com/databasifylabs/databasify/releases/tag/$version", 'notes' => "## The app\n\n- Update from the app"];
}

it('stores the latest release and when it was checked', function () {
    Http::fake(['get.databasify.io/*' => Http::response(releaseFeed())]);

    $release = resolve(CheckForRelease::class)->handle();

    expect($release->version)->toBe('0.2.0')
        ->and($release->url)->toBe('https://github.com/databasifylabs/databasify/releases/tag/0.2.0')
        ->and(resolve(LatestReleaseQuery::class)->get()?->checkedAt->isToday())->toBeTrue();
});

it('moves the check time forward when the release did not change', function () {
    Http::fake(['get.databasify.io/*' => Http::response(releaseFeed())]);
    $this->travelTo(now()->subDay());
    resolve(CheckForRelease::class)->handle();
    $this->travelBack();

    resolve(CheckForRelease::class)->handle();

    expect(resolve(LatestReleaseQuery::class)->get()?->checkedAt->isToday())->toBeTrue();
});

it('keeps the previous release and check time when the source fails', function (Closure $response) {
    Http::fake(['get.databasify.io/*' => Http::sequence()->push(releaseFeed())->pushResponse($response())]);
    $this->travelTo(now()->subDay());
    resolve(CheckForRelease::class)->handle();
    $this->travelBack();

    expect(fn () => resolve(CheckForRelease::class)->handle())->toThrow(ValidationException::class, 'get.databasify.io');

    $checked = resolve(LatestReleaseQuery::class)->get();
    expect($checked?->release->version)->toBe('0.2.0')
        ->and($checked?->checkedAt->isYesterday())->toBeTrue();
})->with([
    'server error' => fn () => fn () => Http::response('', 500),
    'invalid json' => fn () => fn () => Http::response('not json'),
    'missing version' => fn () => fn () => Http::response(['notes' => 'x']),
]);

it('stores nothing when the first check fails', function () {
    Http::fake(['get.databasify.io/*' => Http::response('', 404)]);

    expect(fn () => resolve(CheckForRelease::class)->handle())->toThrow(ValidationException::class)
        ->and(resolve(LatestReleaseQuery::class)->get())->toBeNull();
});

it('fails the scheduled command without crashing when the source fails', function () {
    Http::fake(['get.databasify.io/*' => Http::response('', 404)]);

    $this->artisan('updates:check')->assertFailed();
});

it('strips raw html and unsafe links from the notes', function () {
    $release = new Release('0.2.0', now()->toImmutable(), 'u', "<script>alert(1)</script>\n\n[x](javascript:alert(1))\n\n- kept");

    expect($release->notesHtml())->not->toContain('<script>', 'javascript:')
        ->and($release->notesHtml())->toContain('<li>kept</li>');
});

it('compares versions', function (string $running, bool $newer) {
    expect(new Release('0.2.0', now()->toImmutable(), 'u', '')->isNewerThan($running))->toBe($newer);
})->with([['0.1.0', true], ['0.2.0', false], ['0.10.0', false]]);

it('checks from the scheduled command', function () {
    Http::fake(['get.databasify.io/*' => Http::response(releaseFeed('0.3.0'))]);

    $this->artisan('updates:check')->assertSuccessful();

    expect(resolve(LatestReleaseQuery::class)->get()?->release->version)->toBe('0.3.0');
});
