<?php

declare(strict_types=1);

namespace App\Engines\Drivers\Sentinel;

use App\Engines\Data\Operation;
use App\Engines\Payload;

final readonly class SentinelScripts
{
    /**
     * @param  array<string, string>  $env
     */
    public function run(SentinelDriver $driver, string $script, array $env = [], int $timeout = 120): void
    {
        $driver->run("sentinel/{$script}", ['engine' => $driver::flavor()], $env, timeout: $timeout);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function read(SentinelDriver $driver, string $script, array $env = [], int $timeout = 120): Payload
    {
        return $driver->read("sentinel/{$script}", ['engine' => $driver::flavor()], $env, timeout: $timeout);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function start(SentinelDriver $driver, string $key, string $script, array $env = []): Operation
    {
        return $driver->task($key, "sentinel/{$script}", ['engine' => $driver::flavor()], $env);
    }
}
