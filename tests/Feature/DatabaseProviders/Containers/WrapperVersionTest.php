<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

function requireWrapperWith(string $label): ProcessResult
{
    $script = "docker() { printf '%s' '{$label}'; }\n"
        .view('ssh.databases.containers.pull-image', ['minimumWrapper' => config()->string('databasify.images.minimum_wrapper')])->render()
        ."\nrequire_wrapper ghcr.io/databasifylabs/databasify-postgres@sha256:abc\n";

    return Process::input($script)->run(['bash']);
}

it('accepts images built with the minimum wrapper or newer', function (string $label) {
    config(['databasify.images.minimum_wrapper' => '0.2.0']);

    expect(requireWrapperWith($label)->successful())->toBeTrue();
})->with(['same version' => '0.2.0', 'newer patch' => '0.2.1', 'newer minor' => '0.10.0']);

it('refuses images built with an older wrapper or none at all', function (string $label, string $shown) {
    config(['databasify.images.minimum_wrapper' => '0.2.0']);

    $result = requireWrapperWith($label);

    expect($result->successful())->toBeFalse()
        ->and($result->output())->toContain("runs databasify {$shown}, but this Databasify needs 0.2.0 or newer");
})->with([
    'older' => ['0.1.9', '0.1.9'],
    'missing label' => ['', 'without a version'],
]);
