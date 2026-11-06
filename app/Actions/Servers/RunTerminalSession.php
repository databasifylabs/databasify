<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Contracts\Ssh\ServerConnection;
use App\Events\TerminalOutputReceived;
use App\Models\Server;
use App\Services\Ssh\TerminalSession;
use Carbon\CarbonInterface;
use Throwable;

final readonly class RunTerminalSession
{
    private const int CHUNK_BYTES = 6000;

    public function __construct(private ServerConnection $ssh) {}

    public function handle(Server $server, TerminalSession $session, int $columns, int $rows): void
    {
        $until = now()->addSeconds(TerminalSession::MAX_SECONDS);

        try {
            $this->runShellOver($server, $session, $columns, $rows, $until);
        } catch (Throwable $exception) {
            report($exception);
            $this->send($session, "\r\n{$exception->getMessage()}\r\n");
        } finally {
            $session->close();
            event(new TerminalOutputReceived($session->id, '', closed: true));
        }
    }

    private function runShellOver(Server $server, TerminalSession $session, int $columns, int $rows, CarbonInterface $until): void
    {
        if (! $session->waitForAttach()) {
            return;
        }

        $this->ssh->shell(
            $server,
            $columns,
            $rows,
            fn (string $output) => $this->send($session, $output),
            fn (): ?string => $this->inputBefore($session, $until),
        );
    }

    private function inputBefore(TerminalSession $session, CarbonInterface $until): ?string
    {
        return now()->lessThan($until) ? $session->nextInput() : null;
    }

    private function send(TerminalSession $session, string $output): void
    {
        foreach (str_split($output, self::CHUNK_BYTES) as $chunk) {
            event(new TerminalOutputReceived($session->id, $chunk));
        }
    }
}
