<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use App\Models\Database;

it('serves sql engines on 25060 and valkey and redis on 25061 straight from the engine', function (string $engine, int $port, string $file, string $line) {
    $database = idleDatabase($engine, 1, DatabaseMode::Single);

    expect(Database::providerFor($engine)->ports()->client)->toBe($port)
        ->and(renderedFile($database, $file))->toContain($line);
})->with([
    'mysql' => ['mysql', 25060, 'my.cnf', "port = 25060\n"],
    'mariadb' => ['mariadb', 25060, '99-databasify.cnf', "port = 25060\n"],
    'postgres' => ['postgres', 25060, 'postgresql.conf', "port = '25060'\n"],
    'postgres pool' => ['postgres', 25060, 'pgbouncer.ini', "listen_port = 25061\n"],
    'valkey' => ['valkey', 25061, 'valkey.conf', "tls-port 25061\n"],
    'redis' => ['redis', 25061, 'redis.conf', "tls-port 25061\n"],
    'mongodb' => ['mongodb', 25060, 'mongod.conf', '"port": 25060,'],
]);
