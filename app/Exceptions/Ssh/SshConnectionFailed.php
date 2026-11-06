<?php

declare(strict_types=1);

namespace App\Exceptions\Ssh;

use App\Models\Server;
use RuntimeException;

final class SshConnectionFailed extends RuntimeException
{
    private ?string $serverId = null;

    public static function onServer(Server $server, string $message): self
    {
        $exception = new self($message);
        $exception->serverId = $server->id;

        return $exception;
    }

    /**
     * @return array{server_id?: string}
     */
    public function context(): array
    {
        return $this->serverId === null ? [] : ['server_id' => $this->serverId];
    }
}
