<?php

declare(strict_types=1);

namespace App\Exceptions\Engines;

final class EngineScriptFailed extends EngineError
{
    public static function exited(string $script, int $exit, string $stderr): self
    {
        $lines = array_values(array_filter(explode("\n", $stderr), fn (string $line): bool => trim($line) !== ''));

        return new self('script_failed', "{$script} exited {$exit}: ".(end($lines) ?: 'no error output'));
    }
}
