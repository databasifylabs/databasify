<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Engines\Payload;
use App\Exceptions\Engines\EngineScriptFailed;

final readonly class ScriptResult
{
    private const string RESULT = 'DATABASIFY_RESULT ';

    public function __construct(
        public string $script,
        public string $stdout,
        public bool $truncated = false,
    ) {}

    public function json(): Payload
    {
        if ($this->truncated) {
            throw new EngineScriptFailed("{$this->script} printed more than the engine returns, so its result was cut off.");
        }

        $lines = array_filter(explode("\n", $this->stdout), fn (string $line): bool => str_starts_with($line, self::RESULT));

        if ($lines === []) {
            return $this->wholeOutput();
        }

        return Payload::decode(substr((string) end($lines), strlen(self::RESULT)));
    }

    private function wholeOutput(): Payload
    {
        if (trim($this->stdout) === '') {
            throw new EngineScriptFailed("{$this->script} printed nothing to read.");
        }

        return Payload::decode($this->stdout);
    }
}
