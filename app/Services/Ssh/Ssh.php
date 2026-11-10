<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Contracts\Ssh\ServerConnection;
use App\DTOs\Ssh\LogGroup;
use App\Exceptions\Databases\OperationStopped;
use App\Exceptions\Ssh\RemoteScriptFailed;
use App\Models\Database;
use App\Models\Server;
use App\Models\ServerLog;
use Throwable;

final readonly class Ssh
{
    public const int TIMEOUT = 3600;

    public const int PROBE_TIMEOUT = 90;

    public const int APT_LOCK_TIMEOUT = 600;

    public function __construct(
        private Server $server,
        private ServerConnection $connection,
        private ?Database $database = null,
    ) {}

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    public function exec(string $view, array $data = [], ?string $log = null, ?int $timeout = null, bool $secret = false): string
    {
        $this->ensureNotStopped($log);
        $serverLog = $log === null ? null : $this->logNamed($log);
        $timeout ??= $serverLog === null ? self::PROBE_TIMEOUT : self::TIMEOUT;
        $output = '';

        try {
            $exitCode = $this->connection->run($this->server, $this->render($view, $data), function (string $chunk) use (&$output, $serverLog, $secret): void {
                $output .= $chunk;

                if (! $secret) {
                    $serverLog?->write($chunk);
                }
            }, $timeout);
        } catch (Throwable $exception) {
            $serverLog?->fail($exception->getMessage());

            throw $exception;
        }

        $serverLog?->finish($exitCode);

        if ($exitCode !== 0) {
            throw RemoteScriptFailed::withExitCode(($log ?? $view)." on {$this->server->name}", $exitCode, $this->server, $view);
        }

        return $output;
    }

    private function ensureNotStopped(?string $log): void
    {
        if ($log === null || $this->database?->isStopRequested() !== true) {
            return;
        }

        $this->server->logFailure($log, 'Stopped by you before this ran.', $this->database);

        throw new OperationStopped("{$this->database->name} was stopped by you.");
    }

    private function logNamed(string $name): ServerLog
    {
        $group = LogGroup::fromContext();

        return $this->server->serverLogs()->create(['name' => $name, 'database_id' => $this->database?->id, 'group_id' => $group?->id, 'group_name' => $group?->name]);
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data): string
    {
        $variables = ['aptTimeout' => self::APT_LOCK_TIMEOUT];

        return "set -euo pipefail\nexport DEBIAN_FRONTEND=noninteractive\n"
            .view('ssh.servers.os.apt', $variables)->render()
            .view($view, [...$variables, ...$data])->render();
    }
}
