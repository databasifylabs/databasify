<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Ssh\ServerConnection;
use App\Models\Server;
use Closure;
use Throwable;

final class FakeServerConnection implements ServerConnection
{
    /** @var list<array{server: string, script: string, timeout: int}> */
    public array $runs = [];

    /** @var list<array{server: string, columns: int, rows: int}> */
    public array $shells = [];

    /** @var list<string> */
    public array $typed = [];

    public string $shellOutput = '';

    /** @var list<array{output: string, exit: int}> */
    private array $responses = [];

    private ?Throwable $failure = null;

    /** @var (Closure(string): string)|null */
    private ?Closure $bootIds = null;

    /** @var list<array{matches: Closure(string, string): bool, output: string, exit: int}> */
    private array $rules = [];

    public function respond(string $output = '', int $exit = 0): self
    {
        $this->responses[] = ['output' => $output, 'exit' => $exit];

        return $this;
    }

    /**
     * @param  Closure(string, string): bool  $matches
     */
    public function respondWhen(Closure $matches, string $output = '', int $exit = 0): self
    {
        $this->rules[] = ['matches' => $matches, 'output' => $output, 'exit' => $exit];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    public function respondToEngine(string $command, array $reply, int $exit = 0): self
    {
        return $this->respondWhen(
            fn (string $server, string $script): bool => str_contains($script, "| databasify {$command} "),
            json_encode((object) $reply, JSON_THROW_ON_ERROR),
            $exit,
        );
    }

    public function engineRequestAt(int $index): string
    {
        if (preg_match("/^printf '%s' '([A-Za-z0-9+\/=]+)' \| base64 -d \| databasify /m", $this->scriptAt($index), $payload) !== 1) {
            return '';
        }

        $request = base64_decode($payload[1], true);

        return $request === false ? '' : $request;
    }

    public function bootIdChangesAfterReboot(): self
    {
        $reboots = [];
        $this->respondWhen(function (string $server, string $script) use (&$reboots): bool {
            if (str_contains($script, 'sleep 2; reboot')) {
                $reboots[$server] = ($reboots[$server] ?? 0) + 1;
            }

            return false;
        });
        $this->bootIds = function (string $server) use (&$reboots): string {
            return 'boot-'.($reboots[$server] ?? 0);
        };

        return $this;
    }

    public function failWith(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function run(Server $server, string $script, Closure $onOutput, int $timeout = 3600): int
    {
        $this->runs[] = ['server' => $server->name, 'script' => $this->withContainerStepOf($script), 'timeout' => $timeout];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $response = $this->bootIdOf($server->name, $script) ?? $this->ruleFor($server->name, $script) ?? array_shift($this->responses) ?? ['output' => '', 'exit' => 0];
        $onOutput($response['output']);

        return $response['exit'];
    }

    public function shell(Server $server, int $columns, int $rows, Closure $onOutput, Closure $nextInput): void
    {
        $this->shells[] = ['server' => $server->name, 'columns' => $columns, 'rows' => $rows];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $onOutput($this->shellOutput);

        while (($input = $nextInput()) !== null && $input !== '') {
            $this->typed[] = $input;
        }
    }

    public function scriptAt(int $index): string
    {
        return $this->runs[$index]['script'] ?? '';
    }

    /**
     * @return array{output: string, exit: int}|null
     */
    private function bootIdOf(string $server, string $script): ?array
    {
        if ($this->bootIds === null || ! str_contains($script, '/proc/sys/kernel/random/boot_id')) {
            return null;
        }

        return ['output' => ($this->bootIds)($server)."\n", 'exit' => 0];
    }

    /**
     * @return array{output: string, exit: int}|null
     */
    private function ruleFor(string $server, string $script): ?array
    {
        $script = $this->withContainerStepOf($script);
        $rule = collect($this->rules)->first(fn (array $rule): bool => ($rule['matches'])($server, $script));

        return $rule === null ? null : ['output' => $rule['output'], 'exit' => $rule['exit']];
    }

    private function withContainerStepOf(string $script): string
    {
        if (preg_match("/^echo '([A-Za-z0-9+\/=]+)' \| base64 -d \| docker exec -i /m", $script, $payload) !== 1) {
            return $script;
        }

        return $script."\n".base64_decode($payload[1], true);
    }
}
