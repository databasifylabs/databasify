<?php

declare(strict_types=1);

function dockerRecordingCallsTo(string $calls, string $health): string
{
    return "docker() { echo \"\$*\" >> $calls; case \"\$*\" in *'config --images app'*) echo ghcr.io/databasifylabs/databasify:latest ;; *'.State.Health.Status'*) echo $health ;; *'ps -q'*) echo c1 ;; esac; }; sleep() { :; }";
}

/**
 * @return array<string, mixed>
 */
function rollbackStatusIn(string $dir): array
{
    return json_decode((string) file_get_contents("$dir/status.json"), true);
}

it('restarts the recorded image when the new version is not healthy', function () {
    $dir = temporaryDirectory();
    touch("$dir/compose.yaml");
    $calls = temporaryFile();

    $result = installerScript(dockerRecordingCallsTo($calls, 'healthy')."; UPDATE_STATUS=$dir/status.json; LOG=/dev/null; status_start 0.1.0 0.2.0 'Waiting until healthy'; roll_back sha256:old 0.1.0", ['DATABASIFY_DIR' => $dir]);

    expect($result->successful())->toBeTrue()
        ->and(file_get_contents($calls))->toContain('tag sha256:old ghcr.io/databasifylabs/databasify:latest', 'compose up -d')
        ->and(rollbackStatusIn($dir))->toMatchArray(['rolled_back' => true, 'message' => 'Rolled back to 0.1.0'])
        ->and(rollbackStatusIn($dir)['steps'])->toContain(['title' => 'Rolling back to 0.1.0', 'state' => 'done']);
});

it('says so when the rolled back version does not become healthy either', function () {
    $dir = temporaryDirectory();
    touch("$dir/compose.yaml");

    installerScript(dockerRecordingCallsTo(temporaryFile(), 'unhealthy')."; UPDATE_STATUS=$dir/status.json; LOG=/dev/null; status_start 0.1.0 0.2.0 'Waiting until healthy'; roll_back sha256:old 0.1.0", ['DATABASIFY_DIR' => $dir]);

    expect(rollbackStatusIn($dir))->toMatchArray(['rolled_back' => true, 'message' => 'Rolled back to 0.1.0, but it did not become healthy'])
        ->and(rollbackStatusIn($dir)['steps'])->toContain(['title' => 'Rolling back to 0.1.0', 'state' => 'failed']);
});

it('rolls back and fails the step when the new version is not healthy', function () {
    $dir = temporaryDirectory();
    touch("$dir/compose.yaml");
    $calls = temporaryFile();

    $result = installerScript('. "$SOURCE/update.sh" --functions-only; '.dockerRecordingCallsTo($calls, 'unhealthy')."; UPDATE_STATUS=$dir/status.json; LOG=/dev/null; PREVIOUS_IMAGE=sha256:old; PREVIOUS_VERSION=0.1.0; status_start 0.1.0 0.2.0 'Waiting until healthy'; STEP_ON_FAILURE=roll_back_to_previous run_step 'Waiting until healthy' '' wait_for_health", ['DATABASIFY_DIR' => $dir]);

    expect($result->exitCode())->toBe(1)
        ->and(file_get_contents($calls))->toContain('tag sha256:old')
        ->and(rollbackStatusIn($dir))->toMatchArray(['state' => 'failed', 'rolled_back' => true]);
});

it('reports that rollback is unavailable when no container was running', function () {
    $dir = temporaryDirectory();
    touch("$dir/compose.yaml");

    installerScript('. "$SOURCE/update.sh" --functions-only; docker() { :; }; '."UPDATE_STATUS=$dir/status.json; LOG=/dev/null; status_start '' 0.2.0 'Pulling images'; record_previous_image", ['DATABASIFY_DIR' => $dir]);

    expect(rollbackStatusIn($dir))->toMatchArray(['rolled_back' => false, 'message' => 'No running version found, rollback not available']);
});

it('says in the terminal and the log that rollback is not available', function () {
    $dir = temporaryDirectory();
    touch("$dir/compose.yaml");
    $log = temporaryFile();

    $output = installerScript('. "$SOURCE/update.sh" --functions-only; docker() { :; }; '."LOG=$log; record_previous_image", ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain('No running version found, rollback not available')
        ->and(file_get_contents($log))->toContain('No running version found, rollback not available');
});

it('fails the step without rolling back when no container was running', function () {
    $dir = temporaryDirectory();
    touch("$dir/compose.yaml");
    $calls = temporaryFile();

    $result = installerScript('. "$SOURCE/update.sh" --functions-only; '.dockerRecordingCallsTo($calls, 'unhealthy')."; UPDATE_STATUS=$dir/status.json; LOG=/dev/null; PREVIOUS_IMAGE=; PREVIOUS_VERSION=; status_start '' 0.2.0 'Waiting until healthy'; STEP_ON_FAILURE=roll_back_to_previous run_step 'Waiting until healthy' '' wait_for_health", ['DATABASIFY_DIR' => $dir]);

    expect($result->exitCode())->toBe(1)
        ->and(file_get_contents($calls))->not->toContain('tag ');
});

it('marks a run that exits unexpectedly as failed', function () {
    $dir = temporaryDirectory();

    installerScript('. "$SOURCE/update.sh" --functions-only; '."UPDATE_STATUS=$dir/status.json; status_start 0.1.0 0.2.0 'Pulling images'; trap finish_unfinished_status EXIT; exit 3", ['DATABASIFY_DIR' => $dir]);

    expect(rollbackStatusIn($dir)['state'])->toBe('failed');
});

it('leaves a finished run alone on exit', function () {
    $dir = temporaryDirectory();

    installerScript('. "$SOURCE/update.sh" --functions-only; '."UPDATE_STATUS=$dir/status.json; status_start 0.1.0 0.2.0 'Pulling images'; trap finish_unfinished_status EXIT; status_finish succeeded", ['DATABASIFY_DIR' => $dir]);

    expect(rollbackStatusIn($dir)['state'])->toBe('succeeded');
});

it('reports a rollback that could not restore the image', function () {
    $dir = temporaryDirectory();
    touch("$dir/compose.yaml");
    $calls = temporaryFile();
    $docker = "docker() { echo \"\$*\" >> $calls; case \"\$*\" in *'config --images app'*) echo ghcr.io/databasifylabs/databasify:latest ;; tag*) return 1 ;; esac; }";

    $result = installerScript("$docker; UPDATE_STATUS=$dir/status.json; LOG=/dev/null; status_start 0.1.0 0.2.0 'Waiting until healthy'; roll_back sha256:old 0.1.0 || echo FAILED", ['DATABASIFY_DIR' => $dir]);

    expect($result->output())->toContain('FAILED')
        ->and(file_get_contents($calls))->not->toContain('compose up')
        ->and(rollbackStatusIn($dir))->toMatchArray(['rolled_back' => false, 'message' => 'Rollback to 0.1.0 failed: the previous image could not be restored']);
});

it('rolls back when restarting the containers fails', function () {
    expect((string) file_get_contents(base_path('scripts/update.sh')))
        ->toContain("STEP_ON_FAILURE=roll_back_to_previous run_step 'Restarting containers' '' restart_containers");
});

it('lets only one update run at a time', function () {
    $dir = temporaryDirectory();

    $result = installerScript('. "$SOURCE/update.sh" --functions-only; flock() { return 1; }; LOG=/dev/null; lock_update', ['DATABASIFY_DIR' => $dir]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('Another update is already running');
});

it('takes the update lock when no other update runs', function () {
    $dir = temporaryDirectory();

    $result = installerScript('. "$SOURCE/update.sh" --functions-only; flock() { return 0; }; LOG=/dev/null; lock_update; echo LOCKED', ['DATABASIFY_DIR' => $dir]);

    expect($result->output())->toContain('LOCKED')
        ->and(file_exists("$dir/updater/.lock"))->toBeTrue();
});
