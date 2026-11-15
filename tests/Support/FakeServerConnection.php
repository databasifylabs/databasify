<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Ssh\ServerConnection;
use App\Models\Server;
use Closure;
use Throwable;

final class FakeServerConnection implements ServerConnection
{
    /** @var list<array{server: string, script: string}> */
    private array $runs = [];

    /** @var list<array{needle: string, turns: list<array{output: string, exit: int, failure: Throwable|null}>}> */
    private array $replies = [];

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
}
