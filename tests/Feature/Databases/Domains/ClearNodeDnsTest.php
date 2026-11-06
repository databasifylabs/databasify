<?php

declare(strict_types=1);

use App\Actions\Databases\Domains\ClearNodeDns;
use App\Enums\Databases\DatabaseMode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('removes both names of a read-only node', function () {
    fakeCloudflare();
    $reader = withReadOnlyNode(withZone(idleDatabase('valkey', 1, DatabaseMode::Single)), ['name' => 'reports']);

    resolve(ClearNodeDns::class)->handle($reader);

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'))->toHaveCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('leaves dns alone for nodes without a name or a domain', function (bool $zoned, array $attributes) {
    Http::fake();
    $database = idleDatabase('valkey', 1, DatabaseMode::Single);
    $reader = withReadOnlyNode($zoned ? withZone($database) : $database, $attributes);

    resolve(ClearNodeDns::class)->handle($reader);

    Http::assertNothingSent();
})->with([
    'no name' => [true, []],
    'no domain' => [false, ['name' => 'reports']],
]);
