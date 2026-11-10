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
        ->toEqual(new EngineImage(EngineCatalog::find($engine)->imageRepository, FakeImageRegistry::digestFor($engine, $expected), $expected));
})->with([
    'postgres 18' => ['postgres', '18', ['17.11', '18.6', '18.10', '18', 'latest', '18.11-a1b2c3d', '180.1', '18.12-r3'], '18.10'],
    'mysql 8.4' => ['mysql', '8.4', ['8.0.40', '8.4.9', '8.4.13', '8.4', '8.4.14-0f00ba1', 'latest'], '8.4.13'],
    'valkey 9' => ['valkey', '9', ['8.1.10', '9.1.2', '9.0.3', '9', '9.2.0-deadbee'], '9.1.2'],
]);

it('takes the image repository from the registry and repository settings', function () {
    config(['databasify.images.registry' => 'registry.example.com/acme', 'databasify.images.repository' => 'images-{engine}']);
    fakeImageRegistry()->publish('redis', '8.2.3');

    expect(resolve(ImageResolver::class)->newest(new Database(['engine' => 'redis']), '8')->reference())
        ->toBe('registry.example.com/acme/images-redis@'.FakeImageRegistry::digestFor('redis', '8.2.3'));
});

it('fails when the line has no published version', function () {
    imageRegistry()->publish('mariadb', 'latest');

    expect(fn () => resolve(ImageResolver::class)->newest(new Database(['engine' => 'mariadb']), '12.1'))
        ->toThrow(ImageUnavailable::class, 'No MariaDB 12.1 image is published in ghcr.io/databasifylabs/databasify-mariadb.');
});
