<?php

declare(strict_types=1);

namespace App\Exceptions\Ssh;

use App\Models\Server;
use App\Services\Ssh\Ssh;
use RuntimeException;

final class RemoteScriptFailed extends RuntimeException
{
    private ?string $serverId = null;

    private ?string $script = null;

    public static function withExitCode(string $title, int $exitCode, Server $server, string $script): self
    {
        $exception = new self("{$title} failed with exit code {$exitCode}.", $exitCode);
        $exception->serverId = $server->id;
        $exception->script = $script;

        return $exception;
    }

    public function foundContainerStopped(): bool
    {
        return $this->getCode() === Ssh::CONTAINER_NOT_RUNNING;
    }

    /**
     * @return array{server_id?: string, script?: string}
     */
    public function context(): array
    {
        return array_filter(['server_id' => $this->serverId, 'script' => $this->script], fn (?string $value): bool => $value !== null);
    }
}
