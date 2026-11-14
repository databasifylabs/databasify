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
    $this->scripts->respond('engines.postgres.migration.check', ['reachable' => true, 'error' => null, 'version' => '17.2', 'walLevel' => 'logical', 'canReplicate' => true, 'sizeMb' => 12, 'databases' => ['shop'], 'unreadable' => [], 'unowned' => [], 'occupied' => [], 'warnings' => [], 'problems' => []]);

    $check = $this->node->driver()->migration()->check($this->source, ['shop']);

    expect($check)->reachable->toBeTrue()->databases->toBe(['shop']);
    $this->scripts->assertRan('engines.postgres.migration.check', fn (string $script, array $env): bool => $env['SOURCE_PASSWORD'] === "s3'cr et"
        && $env['SOURCE_SSLMODE'] === 'require' && $env['DATABASES'] === '["shop"]' && ! str_contains($script, 's3'));
});

it('starts a one-time copy in its own unit with the copy script attached', function () {
    $this->node->driver()->migration()->start(new MigrationStartData($this->source, ['shop', 'blog'], false, 'databasify'));

    $this->scripts->assertRan('engines.postgres.migration.start', function (string $script, array $env): bool {
        $copy = base64_decode($env['COPY_SCRIPT']);

        return $env['MIGRATION_DATABASES'] === "shop\nblog" && $env['MIGRATION_OWNER'] === 'databasify' && $env['MIGRATION_CONTINUOUS'] === 'false'
            && str_contains($script, 'ctl run -u databasify-postgres-migration -r')
            && str_contains($copy, 'pg_restore -h /srv/postgres/run') && str_contains($copy, 'CREATE SUBSCRIPTION');
    });
});

it('starts a continuous migration with a publication tagged by the target database', function () {
    $this->node->database->id = '0198c3a1-7e2b-7c4d-9f10-4a5b6c7d8e9f';

    $this->node->driver()->migration()->start(new MigrationStartData($this->source, ['shop'], true, 'databasify'));

    $this->scripts->assertRan('engines.postgres.migration.start', fn (string $script, array $env): bool => $env['MIGRATION_CONTINUOUS'] === 'true'
        && $env['MIGRATION_PUBLICATION'] === 'databasify_migration_4a5b6c7d8e9f'
        && str_contains(base64_decode($env['COPY_SCRIPT']), 'CREATE PUBLICATION'));
});

it('refuses a migration without an owner', function () {
    expect(fn () => $this->node->driver()->migration()->start(new MigrationStartData($this->source, [], false, null)))->toThrow(InvalidEngineRequest::class, 'PostgreSQL migrations need an owner');
});

it('reads progress and stops the migration', function () {
    $this->scripts->respond('engines.postgres.migration.progress', ['copy' => 'running', 'log' => 'copying shop', 'lagSeconds' => null]);

    $progress = $this->node->driver()->migration()->progress();
    $this->node->driver()->migration()->stop('run.migration-stop');

    expect($progress)->phase->toBe(MigrationPhase::Working)->detail->toBe('copying shop')->lagSeconds->toBeNull();
    $this->scripts->assertRan('engines.postgres.migration.stop', fn (string $script): bool => str_contains($script, 'DROP SUBSCRIPTION :"name"') && str_contains($script, 'DROP PUBLICATION IF EXISTS'));
});

it('offers continuous sync only when the source writes logical WAL', function (string $walLevel, bool $canReplicate) {
    localScripts()
        ->reply('SHOW server_version', '17.2')
        ->reply('SHOW wal_level', $walLevel)
        ->reply('rolsuper OR rolreplication', 't')
        ->reply('pg_database_size', '12')
        ->reply('SELECT datname FROM pg_database', '');

    $check = $this->node->driver()->migration()->check($this->source, []);

    expect($check)->reachable->toBeTrue()->facts->toMatchArray(['wal_level' => $walLevel])
        ->and($check->blockers === [])->toBe($canReplicate);
})->with([
    'logical' => ['logical', true],
    'replica' => ['replica', false],
]);

it('drops the replication slot on the source when the subscription has to let go of it', function () {
    $local = localScripts()
        ->reply('SELECT d.datname', 'shop|databasify_migration_4a5b6c7d8e9f_shop_1a2b3c4d|databasify_migration_4a5b6c7d8e9f')
        ->reply('SELECT subconninfo', "host=db.example.com dbname='shop'")
        ->reply('SELECT subslotname', 'databasify_migration_4a5b6c7d8e9f_shop_1a2b3c4d')
        ->reply('FROM pg_sequences', '')
        ->reply('ALTER SUBSCRIPTION', '')
        ->reply('DROP SUBSCRIPTION', 'ERROR could not connect to the publisher')
        ->reply('pg_drop_replication_slot', '')
        ->reply('DROP PUBLICATION', '');

    $this->node->driver()->migration()->stop('run.migration-stop');

    expect($local->statements())->toContain("SELECT pg_drop_replication_slot(slot_name) FROM pg_replication_slots WHERE slot_name = 'databasify_migration_4a5b6c7d8e9f_shop_1a2b3c4d'");
});
