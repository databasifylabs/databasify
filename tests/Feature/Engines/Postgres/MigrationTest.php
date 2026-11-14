<?php

declare(strict_types=1);

use App\Engines\Data\MigrationSource;
use App\Engines\Data\MigrationStart as MigrationStartData;
use App\Enums\Migrations\MigrationPhase;
use App\Exceptions\Engines\InvalidEngineRequest;

beforeEach(function () {
    $this->scripts = fakeScripts();
    $this->node = engineNode();
    $this->source = new MigrationSource('db.example.com', 5432, 'migrator', "s3'cr et", true);
});

it('checks a source with its credentials only in the env', function () {
    $this->scripts->respond('postgres/migration/check', ['reachable' => true, 'error' => null, 'version' => '17.2', 'walLevel' => 'logical', 'canReplicate' => true, 'sizeMb' => 12, 'databases' => ['shop'], 'unreadable' => [], 'unowned' => [], 'occupied' => [], 'warnings' => [], 'problems' => []]);

    $check = $this->node->driver()->migration()->check($this->source, ['shop']);

    expect($check)->reachable->toBeTrue()->databases->toBe(['shop']);
    $this->scripts->assertRan('postgres/migration/check', fn (string $script, array $env): bool => $env['SOURCE_PASSWORD'] === "s3'cr et"
        && $env['SOURCE_SSLMODE'] === 'require' && $env['DATABASES'] === '["shop"]' && ! str_contains($script, 's3'));
});

it('starts a one-time copy in its own unit with the copy script attached', function () {
    $this->node->driver()->migration()->start(new MigrationStartData($this->source, ['shop', 'blog'], false, 'databasify'));

    $this->scripts->assertRan('postgres/migration/start', function (string $script, array $env): bool {
        $copy = base64_decode($env['COPY_SCRIPT']);

        return $env['MIGRATION_DATABASES'] === "shop\nblog" && $env['MIGRATION_OWNER'] === 'databasify' && $env['MIGRATION_CONTINUOUS'] === 'false'
            && str_contains($script, 'ctl run -u databasify-postgres-migration -r')
            && str_contains($copy, 'pg_restore -h /srv/postgres/run') && str_contains($copy, 'CREATE SUBSCRIPTION');
    });
});

it('refuses what the Go engine refuses', function (bool $continuous, ?string $owner, string $message) {
    expect(fn () => $this->node->driver()->migration()->start(new MigrationStartData($this->source, [], $continuous, $owner)))->toThrow(InvalidEngineRequest::class, $message);
})->with([
    'no owner' => [false, null, 'PostgreSQL migrations need an owner'],
    'continuous without a publication' => [true, 'databasify', 'continuous PostgreSQL migrations need a publication'],
]);

it('reads progress and stops the migration', function () {
    $this->scripts->respond('postgres/migration/progress', ['copy' => 'running', 'log' => 'copying shop', 'lagSeconds' => null]);

    $progress = $this->node->driver()->migration()->progress();
    $this->node->driver()->migration()->stop();

    expect($progress)->phase->toBe(MigrationPhase::Working)->detail->toBe('copying shop')->lagSeconds->toBeNull();
    $this->scripts->assertRan('postgres/migration/stop', fn (string $script): bool => str_contains($script, 'DROP SUBSCRIPTION :"name"') && str_contains($script, 'DROP PUBLICATION IF EXISTS'));
});
