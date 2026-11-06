<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Models\Server;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use phpseclib3\Net\SFTP;

#[Scoped]
final class SshSessions
{
    /** @var array<string, SFTP> */
    private array $sessions = [];

    /**
     * @param  Closure(): SFTP  $connect
     */
    public function sessionFor(Server $server, Closure $connect): SFTP
    {
        $key = $this->keyOf($server);
        $kept = $this->sessions[$key] ?? null;

        if ($kept !== null && $kept->isConnected()) {
            return $kept;
        }

        $kept?->disconnect();

        return $this->sessions[$key] = $connect();
    }

    public function forget(Server $server): void
    {
        $key = $this->keyOf($server);
        ($this->sessions[$key] ?? null)?->disconnect();
        unset($this->sessions[$key]);
    }

    public function abandon(): void
    {
        $this->sessions = [];
    }

    public function closeAll(): void
    {
        foreach ($this->sessions as $session) {
            $session->disconnect();
        }

        $this->sessions = [];
    }

    public function __destruct()
    {
        $this->closeAll();
    }

    private function keyOf(Server $server): string
    {
        return "{$server->id}:{$server->ssh_user}@{$server->address()}:{$server->ssh_port}";
    }
}
