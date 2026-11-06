<?php

declare(strict_types=1);

use App\DTOs\Migrations\MigrationPosition;
use App\Enums\Migrations\MigrationMode;
use App\Enums\Migrations\MigrationStatus;
use Illuminate\Support\Facades\DB;

it('reads a position saved before the cast, ignoring the retired anonymous flag', function () {
    $database = migratingDatabase('mysql');
    $migration = migrationOf($database, MigrationMode::Continuous, MigrationStatus::Syncing);

    DB::table('database_migrations')->where('id', $migration->id)->update(['position' => '{"anonymous":"yes","file":"binlog.000001","position":"4"}']);

    expect($migration->fresh()?->position)->toEqual(new MigrationPosition(file: 'binlog.000001', position: '4'));

    DB::table('database_migrations')->where('id', $migration->id)->update(['position' => '{"binlog":"bin.000001 4"}']);

    expect($migration->fresh()?->position)->toEqual(new MigrationPosition(binlog: 'bin.000001 4'));

    DB::table('database_migrations')->where('id', $migration->id)->update(['position' => null]);

    expect($migration->fresh()?->position)->toEqual(new MigrationPosition);
});

it('stores the position with the same keys as before the cast', function () {
    $migration = migrationOf(migratingDatabase('mysql'), MigrationMode::Continuous);

    $migration->update(['position' => new MigrationPosition(file: 'binlog.000001', position: '4')]);

    expect(DB::table('database_migrations')->where('id', $migration->id)->value('position'))
        ->toBe('{"file":"binlog.000001","position":"4"}');
});

it('refuses a position that is not a MigrationPosition', function () {
    $migration = migrationOf(migratingDatabase('mysql'), MigrationMode::Continuous);

    $migration->update(['position' => ['binlog' => 'x']]);
})->throws(InvalidArgumentException::class);
