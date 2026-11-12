<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Contracts\Engines\EngineScripts;
use App\Engines\EngineClient;
use App\Engines\Requests\RuntimeRun;
use App\Engines\Requests\TaskLog;
use App\Engines\Requests\TaskStart;
use App\Engines\Responses\Operation;
use App\Models\DatabaseNode;
use App\Models\ServerLog;

final readonly class ScriptRunner implements EngineScripts
{
    private const string PHASE = 'DATABASIFY_PHASE ';

    private const int TAIL_LINES = 20;

    private const int TRANSPORT_GRACE = 30;

    public function __construct(
        private EngineClient $engine,
        private ScriptView $views,
    ) {}

    public function run(DatabaseNode $node, string $script, array $data = [], ?string $user = null, int $timeout = 120, ?string $log = null, array $env = []): ScriptResult
    {
        $serverLog = $log === null ? null : $this->engine->openLog($node, $log);
        $request = new RuntimeRun($this->views->render($node, $script, $data), $env, $user, $timeout);
        $reply = $this->engine->sendInto($node, $request, null, $timeout + self::TRANSPORT_GRACE);

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
        return $this->engine->send($node, new TaskStart($key, $this->views->render($node, $script, $data), $env, $user));
    }

    public function phaseOf(DatabaseNode $node, string $key): ?string
    {
        $phases = array_filter(explode("\n", $this->engine->send($node, new TaskLog($key))->lines), fn (string $line): bool => str_starts_with($line, self::PHASE));

        return $phases === [] ? null : substr((string) end($phases), strlen(self::PHASE));
    }

    private function failLog(?ServerLog $serverLog, string $stderr): void
    {
        $serverLog?->write(implode("\n", array_slice(explode("\n", rtrim($stderr)), -self::TAIL_LINES))."\n");
        $serverLog?->finish(1);
    }
}
