<?php

declare(strict_types=1);

namespace App\Engines\Drivers\MySqlFamily;

use App\Engines\Data\Operation;
use App\Engines\Payload;

final readonly class FamilyScripts
{
    /**
     * @param  array<string, string>  $env
     */
    public function run(MySqlFamilyDriver $driver, string $script, array $env = [], int $timeout = 120): void
    {
        $driver->run($this->nameOf($driver, $script), ['engine' => $driver->engine()], $env, timeout: $timeout);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function read(MySqlFamilyDriver $driver, string $script, array $env = [], int $timeout = 120): Payload
    {
        return $driver->read($this->nameOf($driver, $script), ['engine' => $driver->engine()], $env, timeout: $timeout);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function start(MySqlFamilyDriver $driver, string $key, string $script, array $env = []): Operation
    {
        return $driver->task($key, $this->nameOf($driver, $script), ['engine' => $driver->engine()], $env);
    }

    private function nameOf(MySqlFamilyDriver $driver, string $script): string
    {
        return str_replace('{engine}', $driver->engine()->id, $script);
    }
}
