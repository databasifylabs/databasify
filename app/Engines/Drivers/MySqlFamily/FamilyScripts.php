<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\Operation;
use App\Engines\Payload;

final readonly class FamilyScripts
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     * @param  int<1, 3600>  $timeout
     */
    public function run(MySqlFamilyDriver $driver, string $script, array $data = [], array $env = [], int $timeout = 120): void
    {
        $driver->run($this->nameOf($driver, $script), $data, $env, timeout: $timeout);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     * @param  int<1, 3600>  $timeout
     */
    public function read(MySqlFamilyDriver $driver, string $script, array $data = [], array $env = [], int $timeout = 120): Payload
    {
        return $driver->read($this->nameOf($driver, $script), $data, $env, timeout: $timeout);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  int<1, 3600>  $timeout
     */
    public function query(MySqlFamilyDriver $driver, string $view, array $data = [], int $timeout = 120): void
    {
        $driver->query($this->nameOf($driver, $view), $data, $timeout);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  int<1, 3600>  $timeout
     */
    public function queryJson(MySqlFamilyDriver $driver, string $view, array $data = [], int $timeout = 120): Payload
    {
        return $driver->queryJson($this->nameOf($driver, $view), $data, $timeout);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     */
    public function start(MySqlFamilyDriver $driver, string $key, string $script, array $data = [], array $env = []): Operation
    {
        return $driver->task($key, $this->nameOf($driver, $script), $data, $env);
    }

    private function nameOf(MySqlFamilyDriver $driver, string $script): string
    {
        return str_replace('{engine}', $driver->engine()->id, $script);
    }
}
