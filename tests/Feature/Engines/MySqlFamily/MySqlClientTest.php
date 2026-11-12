<?php

declare(strict_types=1);

use App\Engines\Drivers\MySqlFamily\MySqlFamilyEngine;
use App\Engines\Scripts\MySqlSql;
use App\Engines\Scripts\ScriptFailure;
use App\Engines\Scripts\ScriptView;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineUnavailable;
use App\Exceptions\Engines\NotPrimary;
use Illuminate\Support\Facades\Process;

it('quotes literals and names the way go-sql-driver interpolates them', function () {
    $sql = new MySqlSql;

    expect($sql->string("a'b\\c\"\n\r\x1a"))->toBe("'a\\'b\\\\c\\\"\\n\\r\\Z'")
        ->and($sql->name('a`b'))->toBe('`a``b`');
});

it('refuses a NUL byte', function (Closure $quote) {
    expect(fn () => $quote("a\0b"))->toThrow(InvalidArgumentException::class);
})->with([
    'string' => [fn (string $value): string => (new MySqlSql)->string($value)],
    'name' => [fn (string $value): string => (new MySqlSql)->name($value)],
]);

it('quotes in bash exactly as in PHP', function (string $engine, string $value) {
    $node = idleDatabase($engine, 1)->nodes()->sole();
    $script = resolve(ScriptView::class)->render($node, 'testing/my-quote', ['engine' => MySqlFamilyEngine::of($engine)]);

    $run = Process::input($script)->env(['VALUE' => $value])->run(['bash', '-euo', 'pipefail', '-s']);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($run->output())->toBe((new MySqlSql)->string($value)."\n".(new MySqlSql)->name($value)."\n");
})->with(['mysql', 'mariadb'])->with([
    'plain' => 'app',
    'single quote' => "o'brien",
    'backslash' => 'a\\b',
    'double quote' => 'say "hi"',
    'newline' => "line\nbreak",
    'carriage return' => "cr\rhere",
    'control z' => "z\x1az",
    'backtick' => 'a`b',
    'ampersand' => 'a&b&&c',
    'everything' => "'\\\"\n\r\x1a`&\$(id)",
]);

function runWithClient(string $engine, string $stderr, int $exit): EngineError
{
    $bin = sys_get_temp_dir().'/my-client-'.uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/timeout", "#!/bin/bash\nshift\nexec \"\$@\"\n");
    file_put_contents("{$bin}/{$engine}", "#!/bin/bash\ncat > /dev/null\nprintf '%s\\n' \"\$CLIENT_STDERR\" >&2\nexit {$exit}\n");
    chmod("{$bin}/timeout", 0755);
    chmod("{$bin}/{$engine}", 0755);
    $node = idleDatabase($engine, 1)->nodes()->sole();
    $script = resolve(ScriptView::class)->render($node, 'testing/my-run', ['engine' => MySqlFamilyEngine::of($engine)]);

    $run = Process::input($script)->env(['PATH' => $bin.':'.getenv('PATH'), 'CLIENT_STDERR' => $stderr])->run(['bash', '-euo', 'pipefail', '-s']);
    Process::run(['rm', '-rf', $bin]);

    return ScriptFailure::of('testing/my-run', (int) $run->exitCode(), $run->output(), $run->errorOutput());
}

it('turns client errors into the codes Go reports', function (string $stderr, string $class, string $message) {
    $error = runWithClient('mysql', $stderr, 1);

    expect($error)->toBeInstanceOf($class)
        ->and($error->getMessage())->toBe($message);
})->with([
    'read only' => ['ERROR 1290 (HY000) at line 1: The MySQL server is running with the --read-only option so it cannot execute this statement', NotPrimary::class, 'The MySQL server is running with the --read-only option so it cannot execute this statement'],
    'super read only' => ['ERROR 1836 (HY000) at line 2: Running in read-only mode', NotPrimary::class, 'Running in read-only mode'],
    'user exists' => ["ERROR 1396 (HY000) at line 1: Operation CREATE USER failed for 'app'@'%'", EngineAlreadyExists::class, "Operation CREATE USER failed for 'app'@'%'"],
    'no socket' => ["ERROR 2002 (HY000): Can't connect to local MySQL server through socket '/srv/mysql/run/mysqld.sock' (2)", EngineUnavailable::class, "Can't connect to local MySQL server through socket '/srv/mysql/run/mysqld.sock' (2)"],
    'other server error' => ['ERROR 1064 (42000) at line 1: You have an error in your SQL syntax', EngineError::class, 'Error 1064 (42000): You have an error in your SQL syntax'],
    'not a server error' => ['mysql: unknown option', EngineError::class, 'mysql exited 1: mysql: unknown option'],
]);

it('runs the MariaDB client for MariaDB', function () {
    expect(runWithClient('mariadb', 'ERROR 1290 (HY000) at line 1: read only', 1))->toBeInstanceOf(NotPrimary::class);
});
