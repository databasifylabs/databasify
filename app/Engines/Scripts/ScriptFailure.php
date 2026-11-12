<?php

declare(strict_types=1);

namespace App\Engines\Scripts;

use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineScriptFailed;

final readonly class ScriptFailure
{
    private const string ERROR = 'DATABASIFY_ERROR ';

    private const string SQL = 'sql';

    public static function of(string $script, int $exit, string $stdout, string $stderr): EngineError
    {
        $marker = strpos($stdout, self::ERROR);

        if ($marker === false) {
            return EngineScriptFailed::exited($script, $exit, $stderr);
        }

        [$code, $message] = array_pad(explode("\n", substr($stdout, $marker + strlen(self::ERROR)), 2), 2, '');
        $code = trim($code);

        if ($code === self::SQL) {
            return new EngineError('internal', self::serverMessageOf($message));
        }

        return EngineError::fromReply($code, trim($message));
    }

    private static function serverMessageOf(string $stderr): string
    {
        $kept = [];

        foreach (explode("\n", $stderr) as $line) {
            $line = (string) preg_replace('/^psql:(?:[^:]*:\d+:)? /', '', $line);

            if ($kept === []) {
                if (preg_match('/^(ERROR:|FATAL:|error:)/', $line) === 1) {
                    $kept[] = $line;
                }

                continue;
            }

            if (preg_match('/^(HINT:|QUERY:|CONTEXT:|STATEMENT:)/', $line) === 1) {
                break;
            }

            if (preg_match('/^LINE \d+:|^\s*\^+\s*$/', $line) !== 1) {
                $kept[] = $line;
            }
        }

        $message = rtrim(implode("\n", $kept), "\n ");

        return $message === '' ? 'the statement failed' : $message;
    }
}
