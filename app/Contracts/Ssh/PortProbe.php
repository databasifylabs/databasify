<?php

declare(strict_types=1);

namespace App\Contracts\Ssh;

use App\Services\Ssh\TcpPortProbe;
use Illuminate\Container\Attributes\Bind;

#[Bind(TcpPortProbe::class)]
interface PortProbe
{
    public function isOpen(string $host, int $port): bool;
}
