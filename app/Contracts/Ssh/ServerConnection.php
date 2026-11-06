<?php

declare(strict_types=1);

namespace App\Contracts\Ssh;

use App\Models\Server;
use App\Services\Ssh\PhpseclibConnection;
use Closure;
use Illuminate\Container\Attributes\Bind;

#[Bind(PhpseclibConnection::class)]
interface ServerConnection
{
    /**
     * @param  Closure(string): void  $onOutput
     */
    public function run(Server $server, string $script, Closure $onOutput, int $timeout = 3600): int;

    /**
     * @param  Closure(string): void  $onOutput
     * @param  Closure(): ?string  $nextInput
     */
    public function shell(Server $server, int $columns, int $rows, Closure $onOutput, Closure $nextInput): void;
}
