<?php

declare(strict_types=1);

use App\Engines\Scripts\ScriptFailure;
use App\Engines\Scripts\ScriptView;
use App\Exceptions\Engines\EngineAlreadyExists;
use App\Exceptions\Engines\EngineError;
use App\Exceptions\Engines\EngineUnavailable;
use App\Exceptions\Engines\NotPrimary;
use Illuminate\Support\Facades\Process;

function runMongoScript(string $stdout, string $stderr, int $exit): array
{
    $bin = sys_get_temp_dir().'/mongosh-'.uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/timeout", "#!/bin/bash\nshift\nexec \"\$@\"\n");
    file_put_contents("{$bin}/mongosh", "#!/bin/bash\nfor arg in \"\$@\"; do printf '%s\\n' \"\$arg\"; done > \"\$STUB_ARGS\"\nprintf '%s' \"\$STUB_STDOUT\"\nprintf '%s' \"\$STUB_STDERR\" >&2\nexit \"\$STUB_EXIT\"\n");
    chmod("{$bin}/timeout", 0755);
    chmod("{$bin}/mongosh", 0755);
    $script = resolve(ScriptView::class)->render(idleDatabase('mongodb', 1)->nodes()->sole(), 'testing/mongo-run');

    $run = Process::input($script)->env(['PATH' => $bin.':'.getenv('PATH'), 'STUB_STDOUT' => $stdout, 'STUB_STDERR' => $stderr, 'STUB_EXIT' => (string) $exit, 'STUB_ARGS' => "{$bin}/args", 'WORD' => "it's"])->run(['bash', '-euo', 'pipefail', '-s']);
    $args = (string) @file_get_contents("{$bin}/args");
    Process::run(['rm', '-rf', $bin]);

    return [$run, $args];
}

it('passes the result through and keeps secrets off argv', function () {
    [$run, $args] = runMongoScript("DATABASIFY_RESULT {\"said\":\"it's\"}\n", '', 0);

    expect($run->successful())->toBeTrue()
        ->and($run->output())->toContain('DATABASIFY_RESULT {"said":"it\'s"}')
        ->and($args)->toContain('mongodb://127.0.0.1:25060/admin?directConnection=true&tls=true&tlsAllowInvalidCertificates=true')
        ->and($args)->toContain('--file');
});

it('turns script and connection failures into the codes Go reports', function (string $stdout, string $stderr, int $exit, string $class) {
    [$run] = runMongoScript($stdout, $stderr, $exit);

    expect(ScriptFailure::of('testing/mongo-run', (int) $run->exitCode(), $run->output(), $run->errorOutput()))->toBeInstanceOf($class);
})->with([
    'not primary' => ["DATABASIFY_ERROR not_primary\nnot primary\n", '', 3, NotPrimary::class],
    'duplicate user' => ["DATABASIFY_ERROR already_exists\nUser \"a@admin\" already exists\n", '', 3, EngineAlreadyExists::class],
    'refused' => ['', 'MongoNetworkError: connect ECONNREFUSED 127.0.0.1:25060', 1, EngineUnavailable::class],
    'other' => ['', 'SyntaxError: Unexpected token', 1, EngineError::class],
]);

it('maps server error codes in the prelude like the Go client', function () {
    $prelude = view('engines.mongodb._prelude')->render();
    $probe = <<<'JS'
        const print = (line) => console.log(line);
        const quit = () => {};
        const EJSON = { stringify: JSON.stringify };
        const process = { env: {} };
        const codes = [10107, 13435, 11000, 51003, 51002, 11, undefined].map((code) => codeOf({ code }));
        console.log(JSON.stringify(codes.concat([codeOf({ name: 'MongoNetworkError', message: 'x' }), codeOf(failure('not_found', 'x'))])));
        JS;

    $run = Process::input(str_replace('const env = process.env;', 'let env;', $prelude)."\n".$probe."\nenv = process.env;\n")->run(['node', '-']);

    expect(json_decode(trim($run->output()), true))->toBe(['not_primary', 'not_primary', 'already_exists', 'already_exists', 'already_exists', 'internal', 'internal', 'unavailable', 'not_found']);
});
