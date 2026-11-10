<?php

declare(strict_types=1);

use App\DTOs\Databases\VersionLine;
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
