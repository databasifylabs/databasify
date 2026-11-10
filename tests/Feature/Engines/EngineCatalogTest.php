<?php

declare(strict_types=1);

use App\DTOs\Databases\VersionLine;
use App\Engines\Engine;
use App\Engines\EngineCatalog;
use App\Exceptions\Databases\UnknownEngine;
use App\Plugins\RegisterEngine;

it('lists the six engines with their lines and labels', function () {
    $engines = EngineCatalog::all();
    $mysql = EngineCatalog::find('mysql');
    $mongodb = EngineCatalog::find('mongodb');

    expect($engines->keys()->all())->toBe(['valkey', 'redis', 'mysql', 'postgres', 'mariadb', 'mongodb'])
        ->and($engines->map->label->all())->toBe(['valkey' => 'Valkey', 'redis' => 'Redis', 'mysql' => 'MySQL', 'postgres' => 'PostgreSQL', 'mariadb' => 'MariaDB', 'mongodb' => 'MongoDB'])
        ->and($mysql)
        ->category->toBe('Application')
        ->defaultLine->toBe('8.4')
        ->imageRepository->toBe('ghcr.io/databasifylabs/databasify-mysql')
        ->icon->toBe(asset('assets/icons/database-providers/mysql.svg'))
        ->and(array_map(fn (VersionLine $line): string => $line->value, $mysql->lines))->toBe(['8.4', '9.7'])
        ->and($mongodb->lines[2]->value)->toBe('9.0')->and($mongodb->lines[2]->archives)->toBeFalse()
        ->and($mongodb->serviceRecord)->prefix->toBe('_mongodb._tcp')->options->toBe('replicaSet=databasify&authSource=admin')
        ->and(EngineCatalog::find('valkey'))->category->toBe('Caching')->publicCertificate->toBeTrue()->persistenceChoice->toBeTrue()
        ->and(EngineCatalog::find('postgres'))->publicCertificate->toBeFalse()->serviceRecord->toBeNull();
});

it('offers every supported line with its label and default', function () {
    $offered = EngineCatalog::all()->map(fn (Engine $engine): array => array_map(fn (VersionLine $line): array => $line->toArray() + ['archives' => $line->archives], $engine->lines));
    $line = fn (string $value, string $label, bool $default = false, bool $archives = true): array => ['value' => $value, 'label' => $label, 'default' => $default, 'archives' => $archives];

    expect($offered->all())->toBe([
        'valkey' => [$line('7', 'Valkey 7'), $line('8', 'Valkey 8'), $line('9', 'Valkey 9', true)],
        'redis' => [$line('7.2', 'Redis 7.2'), $line('8', 'Redis 8', true)],
        'mysql' => [$line('8.4', 'MySQL 8.4 LTS', true), $line('9.7', 'MySQL 9.7 LTS')],
        'postgres' => [$line('15', 'PostgreSQL 15'), $line('16', 'PostgreSQL 16'), $line('17', 'PostgreSQL 17'), $line('18', 'PostgreSQL 18', true)],
        'mariadb' => [$line('10.11', 'MariaDB 10.11 LTS'), $line('11.4', 'MariaDB 11.4 LTS'), $line('11.8', 'MariaDB 11.8 LTS', true), $line('12.3', 'MariaDB 12.3 LTS')],
        'mongodb' => [$line('7.0', 'MongoDB 7.0'), $line('8.0', 'MongoDB 8.0', true), $line('9.0', 'MongoDB 9.0', false, false)],
    ]);
});

it('lets a plugin register an engine', function () {
    RegisterEngine::make('clickhouse')->label('ClickHouse')->category('Application')->image('ghcr.io/acme/databasify-clickhouse')->versions('25.8', [['value' => '25.8', 'label' => 'ClickHouse 25.8']])->register();

    expect(EngineCatalog::find('clickhouse'))
        ->label->toBe('ClickHouse')
        ->imageRepository->toBe('ghcr.io/acme/databasify-clickhouse')
        ->defaultLine->toBe('25.8')
        ->and(EngineCatalog::all()->keys()->last())->toBe('clickhouse');
});

it('throws for an unknown engine', function () {
    expect(fn () => EngineCatalog::find('oracle'))->toThrow(UnknownEngine::class, 'No engine is registered as "oracle". Install or enable the plugin that adds it.');
});
