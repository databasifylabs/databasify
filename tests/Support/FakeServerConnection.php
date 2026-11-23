<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Ssh\ServerConnection;
use App\Models\Server;
use Closure;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

final class FakeServerConnection implements ServerConnection
{
    /** @var list<array{server: string, script: string}> */
    private array $runs = [];

    /** @var list<array{needle: string, turns: list<array{output: string, exit: int, failure: Throwable|null}>}> */
    private array $replies = [];

    /** @var list<array{server: string, local: string, remote: string}> */
    private array $puts = [];

    /** @var array<string, string> */
    private array $served = [];

    public function replyTo(string $needle, string $output, int $exit = 0): self
    {
        return $this->replyInTurn($needle, [[$output, $exit]]);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $turns
     */
    public function replyInTurn(string $needle, array $turns): self
    {
        $this->replies[] = ['needle' => $needle, 'turns' => array_map(fn (array $turn): array => ['output' => $turn[0], 'exit' => $turn[1], 'failure' => null], $turns)];

        return $this;
    }

    public function failOn(string $needle, Throwable $failure): self
    {
        $this->replies[] = ['needle' => $needle, 'turns' => [['output' => '', 'exit' => 0, 'failure' => $failure]]];

        return $this;
    }

    public function run(Server $server, string $script, Closure $onOutput, int $timeout = 3600): int
    {
        $this->runs[] = ['server' => $server->name, 'script' => $script];
        $index = collect($this->replies)->search(fn (array $reply): bool => str_contains($script, $reply['needle']));

        if ($index === false) {
            return 0;
        }

        $turns = $this->replies[$index]['turns'];
        $reply = count($turns) > 1 ? array_shift($turns) : $turns[0];
        $this->replies[$index]['turns'] = $turns;

        if ($reply['failure'] !== null) {
            throw $reply['failure'];
        }

        $onOutput($reply['output']);

        return $reply['exit'];
    }

    public function shell(Server $server, int $columns, int $rows, Closure $onOutput, Closure $nextInput): void {}

    public function ran(string $needle, ?Server $on = null): bool
    {
        return collect($this->runs)->contains(fn (array $run): bool => str_contains($run['script'], $needle) && ($on === null || $run['server'] === $on->name));
    }

    public function ranNothing(): bool
    {
        return $this->runs === [];
    }

    public function putFile(Server $server, string $localPath, string $remotePath): void
    {
        $this->puts[] = ['server' => $server->name, 'local' => $localPath, 'remote' => $remotePath];
    }

    public function getFile(Server $server, string $remotePath, string $localPath): void
    {
        file_put_contents($localPath, $this->served[$remotePath] ?? throw new LogicException("No served file at {$remotePath}."));
    }

    public function serveFile(string $remotePath, string $contents): self
    {
        $this->served[$remotePath] = $contents;

        return $this;
    }

    /**
     * @return Collection<int, array{server: string, local: string, remote: string}>
     */
    public function putFiles(): Collection
    {
        return collect($this->puts);
    }
}
