<?php

declare(strict_types=1);

use App\Enums\Databases\DatabaseMode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

function deployOf(string $engine): string
{
    $database = idleDatabase($engine, 1, DatabaseMode::Single);

    return view('ssh.databases.nodes.deploy', $database->provider()->scriptData()->deploy($database->seed()))->render();
}

it('prunes the images of its repository the node no longer runs once the container runs a new image', function (string $runningImage, string $removed) {
    $script = deployOf('postgres');
    $start = 'if [ -n "$running_image" ] && [ "$running_image" != "$image" ] && container_running';
    $prune = $start.Str::between($script, $start, 'echo "deployed configuration');
    $bin = sys_get_temp_dir().'/docker-'.uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/docker", <<<'BASH'
        #!/bin/bash
        case "$1 $2" in
            "image inspect") echo sha256:current ;;
            "image ls") printf '%s\n' 'ghcr.io/databasifylabs/databasify-postgres sha256:old' 'ghcr.io/databasifylabs/databasify-postgres sha256:current' 'ghcr.io/databasifylabs/databasify-mysql sha256:other' 'ghcr.io/databasifylabs/databasify-postgres sha256:old' ;;
            rmi*) shift; echo "$*" >> "$(dirname "$0")/removed" ;;
        esac
        BASH);
    chmod("{$bin}/docker", 0755);

    Process::env(['PATH' => "{$bin}:".getenv('PATH')])
        ->input("set -euo pipefail\ncontainer_running() { true; }\nimage=new\nrunning_image={$runningImage}\n{$prune}")
        ->run(['bash'])->throw();

    $removedImages = File::exists("{$bin}/removed") ? trim(File::get("{$bin}/removed")) : '';

    expect($removedImages)->toBe($removed)
        ->and(strpos($script, 'step prepare'))->toBeLessThan(strpos($script, $prune));
    File::deleteDirectory($bin);
})->with([
    'recreated on a new image' => ['old', 'sha256:old'],
    'already on the image' => ['new', ''],
    'created fresh' => ["''", ''],
]);

it('gives up on an unreachable registry after four quick probes without starting a pull', function () {
    $helpers = view('ssh.databases.containers.pull-image', ['minimumWrapper' => config()->string('databasify.images.minimum_wrapper')])->render();
    $stubs = <<<'BASH'
        curl() { echo "probe $*" >&2; return 28; }
        timeout() { echo "pull" >&2; }
        sleep() { :; }
        BASH;

    $result = Process::input("{$stubs}\n{$helpers}\npull_image ghcr.io/databasifylabs/databasify-postgres@sha256:abc")->run(['bash']);

    expect($result->exitCode())->toBe(1)
        ->and(substr_count($result->errorOutput(), 'probe -sS -o /dev/null --connect-timeout 5 --max-time 10 https://ghcr.io/v2/'))->toBe(4)
        ->and($result->errorOutput())->not->toContain('pull')
        ->and($result->output())->toContain('could not pull ghcr.io/databasifylabs/databasify-postgres@sha256:abc, check that this server reaches ghcr.io');
});

it('pulls with a time limit once the registry answers', function () {
    $helpers = view('ssh.databases.containers.pull-image', ['minimumWrapper' => config()->string('databasify.images.minimum_wrapper')])->render();
    $stubs = <<<'BASH'
        curl() { :; }
        timeout() { echo "$*" >&2; }
        BASH;

    $result = Process::input("{$stubs}\n{$helpers}\npull_image ghcr.io/databasifylabs/databasify-postgres@sha256:abc")->run(['bash']);

    expect($result->exitCode())->toBe(0)
        ->and(trim($result->errorOutput()))->toBe('900 docker pull -q ghcr.io/databasifylabs/databasify-postgres@sha256:abc');
});
