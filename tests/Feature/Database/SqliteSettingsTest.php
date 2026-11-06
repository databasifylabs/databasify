<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('lets concurrent writers wait instead of failing on a file database', function () {
    $path = storage_path('framework/testing/settings.sqlite');
    File::ensureDirectoryExists(dirname($path));
    touch($path);
    config(['database.connections.settings_check' => [...config('database.connections.sqlite'), 'database' => $path]]);

    expect(DB::connection('settings_check')->selectOne('PRAGMA journal_mode')->journal_mode)->toBe('wal')
        ->and(DB::connection('settings_check')->selectOne('PRAGMA busy_timeout')->timeout)->toBe(5000);

    DB::purge('settings_check');
    File::delete([$path, "{$path}-wal", "{$path}-shm"]);
});
