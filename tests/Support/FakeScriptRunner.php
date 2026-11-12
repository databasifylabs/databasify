<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Responses\Operation;
use App\Engines\Scripts\ScriptResult;
use App\Engines\Scripts\ScriptView;
use App\Enums\Databases\OperationState;
use App\Exceptions\Engines\EngineScriptFailed;
use App\Models\DatabaseNode;
use Closure;
use PHPUnit\Framework\Assert;

final class FakeScriptRunner implements EngineScripts
{
    /** @var array<string, array{result: array<string, mixed>|Closure, exit: int}> */
    private array $responses = [];

    /** @var list<array{script: string, text: string, data: array<string, mixed>, env: array<string, string>}> */
    private array $runs = [];

    public function __construct(private readonly ScriptView $views) {}

    /**
     * @param  array<string, mixed>|Closure(array<string, string>, array<string, mixed>): array<string, mixed>  $result
     */
    public function respond(string $script, array|Closure $result = [], int $exit = 0): self
    {
        $this->responses[$script] = ['result' => $result, 'exit' => $exit];

        return $this;
    }

    public function run(DatabaseNode $node, string $script, array $data = [], ?string $user = null, int $timeout = 120, ?string $log = null, array $env = []): ScriptResult
    {
        $this->record($node, $script, $data, $env);
        $response = $this->responses[$script] ?? ['result' => [], 'exit' => 0];

        if ($response['exit'] !== 0) {
            throw EngineScriptFailed::exited($script, $response['exit'], 'fake failure');
        }

        $result = $response['result'] instanceof Closure ? ($response['result'])($env, $data) : $response['result'];

        return new ScriptResult($script, 'DATABASIFY_RESULT '.json_encode((object) $result, JSON_THROW_ON_ERROR)."\n");
    }

    public function start(DatabaseNode $node, string $key, string $script, array $data = [], ?string $user = null, array $env = []): Operation
    {
        $this->record($node, $script, $data, $env);

        return new Operation($key, 'task', OperationState::Running, 'starting', null, null);
    }

    public function phaseOf(DatabaseNode $node, string $key): ?string
    {
        return null;
    }

    /**
     * @param  (Closure(string, array<string, string>): bool)|null  $check
     */
    public function assertRan(string $script, ?Closure $check = null): self
    {
        $matching = array_filter($this->runs, fn (array $run): bool => $run['script'] === $script && ($check === null || $check($run['text'], $run['env'])));
        Assert::assertNotEmpty($matching, "The script {$script} did not run as expected. Ran: ".implode(', ', $this->ran()));

        return $this;
    }

    public function assertNotRan(string $script): self
    {
        Assert::assertNotContains($script, $this->ran());

        return $this;
    }

    public function textOf(string $script): string
    {
        $runs = array_values(array_filter($this->runs, fn (array $run): bool => $run['script'] === $script));
        Assert::assertNotEmpty($runs, "The script {$script} did not run.");

        return end($runs)['text'];
    }

    /**
     * @return list<string>
     */
    public function ran(): array
    {
        return array_column($this->runs, 'script');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $env
     */
    private function record(DatabaseNode $node, string $script, array $data, array $env): void
    {
        $this->runs[] = ['script' => $script, 'text' => $this->views->render($node, $script, $data), 'data' => $data, 'env' => $env];
    }
}
