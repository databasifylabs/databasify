<?php

declare(strict_types=1);

use App\DTOs\Databases\EngineImage;
use App\Engines\EngineCatalog;
use App\Exceptions\Images\ImageUnavailable;
use App\Models\Database;
use App\Services\Images\ImageResolver;
use Tests\Support\FakeImageRegistry;

it('picks the highest exact version of the line and ignores every other tag', function (string $engine, string $line, array $tags, string $expected) {
    collect($tags)->each(fn (string $tag) => withImage($engine, $tag));

    expect(resolve(ImageResolver::class)->newest(new Database(['engine' => $engine]), $line))
        ->toEqual(new EngineImage(EngineCatalog::find($engine)->imageRepository, FakeImageRegistry::digestFor($engine, $expected), $expected, '0.2.2'));
})->with([
    'postgres 18' => ['postgres', '18', ['17.11', '18.6', '18.10', '18', 'latest', '18.11-a1b2c3d', '180.1', '18.12-r3'], '18.10'],
    'mysql 8.4' => ['mysql', '8.4', ['8.0.40', '8.4.9', '8.4.13', '8.4', '8.4.14-0f00ba1', 'latest'], '8.4.13'],
    'valkey 9' => ['valkey', '9', ['8.1.10', '9.1.2', '9.0.3', '9', '9.2.0-deadbee'], '9.1.2'],
]);

it('takes the image repository from the registry setting', function () {
    config(['databasify.registry' => 'registry.example.com/acme']);
    fakeImageRegistry()->publish('redis', '8.2.3');

    expect(resolve(ImageResolver::class)->newest(new Database(['engine' => 'redis']), '8')->reference())
        ->toBe('registry.example.com/acme/databasify-redis@'.FakeImageRegistry::digestFor('redis', '8.2.3'));
});

it('fails when the line has no published version', function () {
    imageRegistry()->publish('mariadb', 'latest');

    expect(fn () => resolve(ImageResolver::class)->newest(new Database(['engine' => 'mariadb']), '12.1'))
        ->toThrow(ImageUnavailable::class, 'No MariaDB 12.1 image is published in ghcr.io/databasifylabs/databasify-mariadb.');
});

it('reads v-prefixed and build-tagged SDK versions', function (string $sdk) {
    withImage('valkey', '9.0.3', sdk: $sdk);

    expect(resolve(ImageResolver::class)->newest(new Database(['engine' => 'valkey']), '9')->sdk)->toBe($sdk);
})->with(['v0.2.3', '0.2.3+abc']);

it('refuses an image without an SDK label', function () {
    withImage('valkey', '9.0', sdk: null);
    withImage('valkey', '9.0.3', sdk: null);

    expect(fn () => resolve(ImageResolver::class)->newest(new Database(['engine' => 'valkey']), '9'))
        ->toThrow(ImageUnavailable::class, 'Valkey 9.0.3 is built on SDK unknown; this Databasify supports ^0.2.2 || ^1.0.');
});

it('falls back to the newest release of the line built on a supported SDK', function () {
    withImage('valkey', '9.0.2');
    withImage('valkey', '9.0.3');
    withImage('valkey', '9.0.4', sdk: '0.3.0');

    expect(resolve(ImageResolver::class)->newest(new Database(['engine' => 'valkey']), '9')->release)->toBe('9.0.3');
});

it('refuses with the newest release when no release of the line is supported', function () {
    withImage('valkey', '9.0', sdk: '0.0.9');
    withImage('valkey', '9.0.3', sdk: '0.0.9');
    withImage('valkey', '9.0.4', sdk: '0.3.0');

    expect(fn () => resolve(ImageResolver::class)->newest(new Database(['engine' => 'valkey']), '9'))
        ->toThrow(ImageUnavailable::class, 'Valkey 9.0.4 is built on SDK 0.3.0; this Databasify supports ^0.2.2 || ^1.0.');
});
