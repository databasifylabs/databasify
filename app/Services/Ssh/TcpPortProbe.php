<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Contracts\Ssh\PortProbe;

final readonly class TcpPortProbe implements PortProbe
{
    private const int TIMEOUT_SECONDS = 3;

    public function isOpen(string $host, int $port): bool
    {
        $socket = rescue(fn () => stream_socket_client("tcp://{$host}:{$port}", timeout: self::TIMEOUT_SECONDS), false, report: false);

        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
