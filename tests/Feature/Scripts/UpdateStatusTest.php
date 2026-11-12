<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function statusIn(string $dir): array
{
    return json_decode((string) file_get_contents("$dir/status.json"), true);
}

it('records each step as it runs and skips the ones that never ran', function () {
    $dir = temporaryDirectory();

    installerScript("UPDATE_STATUS=$dir/status.json; LOG=/dev/null; status_start 0.1.0 0.2.0 'One' 'Two' 'Three'; run_step 'One' '' true; status_finish succeeded");

    expect(statusIn($dir))->toMatchArray(['state' => 'succeeded', 'from' => '0.1.0', 'to' => '0.2.0', 'rolled_back' => false, 'message' => ''])
        ->and(statusIn($dir)['steps'])->toBe([['title' => 'One', 'state' => 'done'], ['title' => 'Two', 'state' => 'skipped'], ['title' => 'Three', 'state' => 'skipped']])
        ->and(statusIn($dir)['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(statusIn($dir)['finished_at'])->not->toBeNull();
});

it('marks the failed step and the whole run as failed', function () {
    $dir = temporaryDirectory();

    $result = installerScript("UPDATE_STATUS=$dir/status.json; LOG=/dev/null; status_start 0.1.0 0.2.0 'One' 'Two'; run_step 'One' '' false");

    expect($result->exitCode())->toBe(1)
        ->and(statusIn($dir)['state'])->toBe('failed')
        ->and(statusIn($dir)['steps'])->toBe([['title' => 'One', 'state' => 'failed'], ['title' => 'Two', 'state' => 'skipped']]);
});

it('shows a running step while the run is in progress', function () {
    $dir = temporaryDirectory();

    installerScript("UPDATE_STATUS=$dir/status.json; status_start 0.1.0 0.2.0 'One' 'Two'; status_step One running");

    expect(statusIn($dir))->toMatchArray(['state' => 'running', 'finished_at' => null])
        ->and(statusIn($dir)['steps'][0])->toBe(['title' => 'One', 'state' => 'running']);
});

it('writes no status file when no status path is set', function () {
    $dir = temporaryDirectory();

    installerScript("cd $dir; LOG=/dev/null; status_start 0.1.0 0.2.0 'One'; run_step 'One' '' true");

    expect(glob("$dir/*"))->toBe([]);
});

it('keeps the status valid json when a message has quotes, tabs or new lines', function () {
    $dir = temporaryDirectory();

    installerScript("UPDATE_STATUS=$dir/status.json; status_start 0.1.0 0.2.0 'One'; status_note false \"\$(printf 'a \\\\ \\\"b\\\"\\tc\\nd\\re')\"");

    expect(statusIn($dir)['message'])->toBe("a \\ \"b\"\tc\nd\re");
});
