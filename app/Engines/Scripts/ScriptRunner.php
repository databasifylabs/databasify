<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Contracts\Engines\EngineScripts;
use App\Engines\Data\Operation;
use App\Engines\Runtime\Runtime;
use App\Models\DatabaseNode;
use App\Models\ServerLog;
use Throwable;

final readonly class ScriptRunner implements EngineScripts
{
    private const int TAIL_LINES = 20;

    public function __construct(
        private Runtime $runtime,
        private ScriptView $views,
    ) {}

    public function run(DatabaseNode $node, string $script, array $data = [], ?string $user = null, int $timeout = 120, ?string $log = null, array $env = []): ScriptResult
    {
        $serverLog = $log === null ? null : $node->openLog($log);

        try {
            $reply = $this->runtime->run($node, $this->views->render($node, $script, $data), ScriptEnvironment::of($node, $env), $user, $timeout);
        } catch (Throwable $exception) {
            $serverLog?->fail($exception->getMessage());

            throw $exception;
        }

        if ($reply->exit !== 0) {
            $this->failLog($serverLog, $reply->stderr);

            throw ScriptFailure::of($script, $reply->exit, $reply->stdout, $reply->stderr);
        }

        $serverLog?->write($reply->stdout);
        $serverLog?->finish(0);

        return new ScriptResult($script, $reply->stdout);
    }

    public function start(DatabaseNode $node, string $key, string $script, array $data = [], ?string $user = null, array $env = []): Operation
    {
        return $this->runtime->startTask($node, $key, $this->views->render($node, $script, $data), ScriptEnvironment::of($node, $env), $user);
    }

    private function failLog(?ServerLog $serverLog, string $stderr): void
    {
        $serverLog?->write(implode("\n", array_slice(explode("\n", rtrim($stderr)), -self::TAIL_LINES))."\n");
        $serverLog?->finish(1);
    }
}
