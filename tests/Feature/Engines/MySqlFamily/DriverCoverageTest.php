<?php

declare(strict_types=1);

use App\Contracts\Engines\EngineDriver;
use App\Contracts\Engines\EngineRequest;
use App\Engines\Drivers\MariaDb\MariaDbDriver;
use App\Engines\Drivers\MongoDb\MongoDbDriver;
use App\Engines\Drivers\MySql\MySqlDriver;
use App\Engines\Drivers\Postgres\PostgresDriver;
use Symfony\Component\Finder\Finder;

it('answers every request the app sends except the binary, host and runtime ones', function (string $driver, array $notOffered) {
    $binaryOnly = ['EngineCapabilities', 'EngineInfo', 'FileGet', 'FilePut', 'HostDeploy', 'HostRemove', 'LogsRead', 'RuntimeInfo', 'RuntimeRun', 'ServiceControl', 'ServiceStatus', 'TaskLog', 'TaskStart', 'TaskStatus', 'TaskStop'];
    $answering = resolve($driver);
    $unanswered = [];

    foreach (Finder::create()->files()->in(app_path('Engines/Requests'))->name('*.php') as $file) {
        $name = $file->getBasename('.php');
        $request = (new ReflectionClass("App\\Engines\\Requests\\{$name}"))->newInstanceWithoutConstructor();
        assert($request instanceof EngineRequest && $answering instanceof EngineDriver);

        if (! $answering->answers($request)) {
            $unanswered[] = $name;
        }
    }

    sort($unanswered);

    expect($unanswered)->toBe(collect([...$binaryOnly, ...$notOffered])->sort()->values()->all());
})->with([
    'postgres' => [PostgresDriver::class, []],
    'mysql' => [MySqlDriver::class, ['PoolCreate', 'PoolDelete', 'PoolList', 'PoolUpdate']],
    'mariadb' => [MariaDbDriver::class, ['PoolCreate', 'PoolDelete', 'PoolList', 'PoolUpdate']],
    'mongodb' => [MongoDbDriver::class, ['PoolCreate', 'PoolDelete', 'PoolList', 'PoolUpdate']],
]);
