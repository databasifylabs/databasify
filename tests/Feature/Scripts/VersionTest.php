<?php

declare(strict_types=1);

it('takes the release version from the source', function () {
    $dir = temporaryPath();

    $result = installerScript('release_version', ['DATABASIFY_DIR' => $dir]);

    expect($result->successful())->toBeTrue()
        ->and(trim($result->output()))->toBe(trim((string) file_get_contents(base_path('scripts/version'))));
});

it('prefers a pinned version in .env', function (string $pin, string $expected) {
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env", "DATABASIFY_VERSION=$pin\n");

    $released = trim((string) file_get_contents(base_path('scripts/version')));
    $output = trim(installerScript('release_version', ['DATABASIFY_DIR' => $dir])->output());

    expect($output)->toBe($expected === 'released' ? $released : $expected);
})->with([['0.3.1', '0.3.1'], ['latest', 'released']]);

it('prints nothing when the source has no version', function () {
    $source = temporaryDirectory();

    $result = installerScript('SOURCE='.$source."\nrelease_version", ['DATABASIFY_DIR' => $source.'/none']);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe('');
});

it('prints nothing when no container is running', function () {
    $dir = temporaryPath();

    $result = installerScript('running_version', ['DATABASIFY_DIR' => $dir]);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe('');
});

it('builds the chip text', function (string $from, string $to, string $expected) {
    expect(installerScript("version_chip '$from' '$to'")->output())->toBe($expected);
})->with([['', '0.4.2', ' v0.4.2 '], ['0.4.2', '0.5.0', ' v0.4.2 → v0.5.0 '], ['0.5.0', '0.5.0', ' v0.5.0 '], ['0.4.2', '', '']]);
