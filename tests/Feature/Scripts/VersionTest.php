<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

function versionSource(string $version): string
{
    $source = temporaryDirectory();
    file_put_contents("$source/version.json", (string) json_encode(['version' => $version, 'published_at' => '2026-11-20T08:00:00Z', 'url' => 'u', 'notes' => ''], JSON_PRETTY_PRINT));

    return $source;
}

it('takes the release version from the source', function () {
    $dir = temporaryPath();

    $result = installerScript('SOURCE='.versionSource('0.4.0')."\nrelease_version", ['DATABASIFY_DIR' => $dir]);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe('0.4.0');
});

it('prefers a pinned version in .env', function (string $pin, string $expected) {
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env", "DATABASIFY_VERSION=$pin\n");

    $output = trim(installerScript('SOURCE='.versionSource('0.4.0')."\nrelease_version", ['DATABASIFY_DIR' => $dir])->output());

    expect($output)->toBe($expected === 'released' ? '0.4.0' : $expected);
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

it('reads the version whatever the indentation', function (string $indent) {
    $source = temporaryDirectory();
    file_put_contents("$source/version.json", "{\n{$indent}\"version\": \"0.7.1\",\n  \"published_at\": \"2026-11-20T08:00:00Z\",\n  \"url\": \"https://github.com/databasifylabs/databasify/releases/tag/0.7.1\",\n  \"notes\": \"\\\"version\\\": \\\"9.9.9\\\" in a note\"\n}\n");

    $output = installerScript('SOURCE='.$source."\npublished_version")->output();

    expect($output)->toBe('0.7.1');
})->with(['two spaces' => '  ', 'four spaces' => '    ', 'tab' => "\t"]);

it('redirects version.json to the file attached to the latest GitHub release', function () {
    expect(file(base_path('scripts/_redirects'), FILE_IGNORE_NEW_LINES))
        ->toContain('/version.json https://github.com/databasifylabs/databasify/releases/latest/download/version.json 302')
        ->and(file_exists(base_path('scripts/version.json')))->toBeFalse()
        ->and(file_exists(base_path('scripts/version')))->toBeFalse();
});

it('attaches version.json to every stable release without committing it', function () {
    $bump = Yaml::parseFile(base_path('.github/workflows/release.yml'))['jobs']['bump'];
    $run = collect($bump['steps'])->pluck('run')->filter()->implode("\n");

    expect($bump['if'])->toBe("\${{ !contains(github.ref_name, '-') }}")
        ->and($run)->toContain('gh release view "$GITHUB_REF_NAME" --json body,publishedAt,url', '> "$RUNNER_TEMP/version.json"', 'gh release upload "$GITHUB_REF_NAME" "$RUNNER_TEMP/version.json" --clobber')
        ->and($run)->not->toContain('scripts/version.json');
});
