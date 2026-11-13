<?php

declare(strict_types=1);

use App\Engines\Data\EngineField;
use App\Engines\Data\Member;
use App\Engines\Data\NodeConfig;
use App\Engines\Drivers\MariaDb\MariaDbFiles;
use App\Engines\Drivers\MariaDb\MariaDbSettings;
use App\Engines\Drivers\MySql\MySqlFiles;
use App\Engines\Drivers\MySql\MySqlSettings;
use App\Engines\Settings\SettingsCatalog;

function familyCatalogOf(string $engine): SettingsCatalog
{
    return $engine === 'mysql' ? MySqlSettings::catalog() : MariaDbSettings::catalog();
}

function withTuning(NodeConfig $request, SettingsCatalog $catalog): NodeConfig
{
    $self = collect($request->members)->firstWhere('ip', $request->ip) ?? new Member($request->ip, 0, false, 1, 1024, 10);

    return new NodeConfig($request->clustered, $request->readOnly, $request->ip, $request->databaseId, $request->members, $catalog->effective($catalog->tuned($self), $request->settings), $request->secrets, $request->archive, $request->primary);
}

it('offers exactly the fields the Go engine offers', function (string $engine) {
    $fields = array_map(fn (EngineField $field): array => $field->toArray(), familyCatalogOf($engine)->fields);

    expect($fields)->toEqual(goFixture('catalog.json', $engine));
})->with(['mysql', 'mariadb']);

it('tunes the same values as the Go engine for each server size', function (string $engine) {
    $sizes = ['small' => [512, 1, 10], 'medium' => [4096, 2, 80], 'large' => [65536, 32, 2000], 'odd' => [7680, 3, 50]];

    foreach ($sizes as $name => [$memory, $cpus, $disk]) {
        $tuned = familyCatalogOf($engine)->tuned(new Member('10.0.0.1', 1, true, $cpus, $memory, $disk));
        ksort($tuned);
        $expected = goFixture('tuning.json', $engine)[$name];
        ksort($expected);

        expect($tuned)->toBe($expected, "{$engine} {$name}");
    }
})->with(['mysql', 'mariadb']);

it('renders the MySQL files and manifest byte for byte like the Go engine', function (string $case) {
    $fixture = goFixture("configure-{$case}.json", 'mysql');

    $files = MySqlFiles::of(withTuning(nodeConfigFromFixture($fixture['request']), MySqlSettings::catalog()), $fixture['line']);

    expect(array_map(fn ($file): array => $file->toArray(), $files->files))->toEqual($fixture['files'])
        ->and($files->manifest)->toBe($fixture['manifest']);
})->with(['standalone-8.4', 'standalone-9.7', 'primary-9.7', 'readonly-8.4']);

it('renders the MariaDB files and manifest byte for byte like the Go engine', function (string $case) {
    $fixture = goFixture("configure-{$case}.json", 'mariadb');

    $files = MariaDbFiles::of(withTuning(nodeConfigFromFixture($fixture['request']), MariaDbSettings::catalog()), $fixture['line'], $fixture['newCluster']);

    expect(array_map(fn ($file): array => $file->toArray(), $files->files))->toEqual($fixture['files'])
        ->and($files->manifest)->toBe($fixture['manifest'])
        ->and(MariaDbFiles::initStatementsOf(nodeConfigFromFixture($fixture['request'])))->toBe($fixture['init']);
})->with(['standalone-10.11', 'standalone-12.3', 'galera-11.8', 'seed-11.4', 'readonly-12.3']);
