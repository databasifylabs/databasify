<?php

declare(strict_types=1);

use App\Engines\Drivers\Postgres\PostgresFiles;
use App\Exceptions\Engines\InvalidEngineRequest;

it('renders every config file byte for byte like the Go engine', function (string $case) {
    $fixture = goFixture("configure-{$case}.json");
    $poolSize = (string) preg_replace('/(?s).*default_pool_size = (\d+).*/', '$1', collect($fixture['files'])->firstWhere('path', '/srv/postgres/conf/pgbouncer.ini')['content']);

    $files = resolve(PostgresFiles::class)->of(nodeConfigFromFixture($fixture['request']), '18', $poolSize);

    expect(array_map(fn ($file): array => ['path' => $file->path, 'mode' => $file->mode, 'content' => $file->content], $files->files))->toEqual($fixture['files'])
        ->and($files->manifest)->toBe($fixture['manifest']);
})->with(['standalone', 'primary', 'readonly']);

it('needs the admin, replication and agent secrets', function () {
    $request = goFixture('configure-standalone.json')['request'];
    $request['secrets']['replication'] = null;

    expect(fn () => resolve(PostgresFiles::class)->of(nodeConfigFromFixture($request), '18', '10'))
        ->toThrow(InvalidEngineRequest::class, 'PostgreSQL needs the admin, replication and agent secrets');
});
