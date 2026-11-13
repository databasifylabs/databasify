<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Ssh\ServerConnection;
use App\Models\DatabaseNode;
use Closure;

final class DriverRecorder
{
    /**
     * @var list<array{call: string, server: string, args: list<mixed>, at: int}>
     */
    public array $calls = [];

    /**
     * @var array<string, mixed>
     */
    private array $answers = [];

    public function answer(string $call, mixed $answer, ?string $server = null): self
    {
        $this->answers[$this->keyOf($call, $server)] = $answer;

        return $this;
    }

    /**
     * @param  list<mixed>  $args
     */
    public function handle(DatabaseNode $node, string $call, array $args, mixed $default = null): mixed
    {
        $server = $node->server->name;
        $this->calls[] = ['call' => $call, 'server' => $server, 'args' => $args, 'at' => $this->sshRuns()];
        $answer = $this->answers[$this->keyOf($call, $server)] ?? $this->answers[$this->keyOf($call, null)] ?? $default;

        return $answer instanceof Closure ? $answer(...$args) : $answer;
    }

    /**
     * @return list<string>
     */
    public function calls(?string $server = null): array
    {
        $calls = array_filter($this->calls, fn (array $call): bool => $server === null || $call['server'] === $server);

        return array_values(array_map(fn (array $call): string => $call['call'], $calls));
    }

    /**
     * @return list<list<mixed>>
     */
    public function argsOf(string $call): array
    {
        return array_values(array_map(fn (array $recorded): array => $recorded['args'], array_filter($this->calls, fn (array $recorded): bool => $recorded['call'] === $call)));
    }

    private function sshRuns(): int
    {
        $ssh = resolve(ServerConnection::class);

        return $ssh instanceof FakeServerConnection ? count($ssh->runs) : 0;
    }

    private function keyOf(string $call, ?string $server): string
    {
        return $server === null ? $call : "{$server}|{$call}";
    }
}
