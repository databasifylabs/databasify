<?php

declare(strict_types=1);

namespace App\Support\Concurrency;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Concurrency\ForkDriver;
use Illuminate\Support\Arr;

final class WorkerSafeForkDriver extends ForkDriver
{
    /**
     * @param  Closure|array<array-key, Closure>  $tasks
     * @return array<array-key, mixed>
     */
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        $tasks = Arr::wrap($tasks);
        $results = (new WorkerSafeFork)->run(...array_values($tasks));
        ksort($results);

        return array_combine(array_keys($tasks), $results);
    }
}
