<?php

declare(strict_types=1);

function updateDirectory(string $env, string $example): string
{
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env", $env);
    file_put_contents("$dir/.env.example", $example);

    return $dir;
}

function workersBelowWhatTheServerFits(): string
{
    return str_replace(['DEFAULT_MAX_PROCESSES=2', 'DATABASES_MAX_PROCESSES=6'], ['DEFAULT_MAX_PROCESSES=1', 'DATABASES_MAX_PROCESSES=3'], workersFor2CpusAnd4Gb());
}

it('reports the new settings it adds', function () {
    $dir = updateDirectory("APP_NAME=Databasify\n", "APP_NAME=Databasify\nREVERB_SCALING=false\nMAIL_PORT=587\n");

    $output = installerScript(". \"\$SOURCE/update.sh\" --functions-only; LOG=/dev/null; run_step 'Adding new settings' '' add_new_settings", ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain('✓ 2 new settings added (REVERB_SCALING, MAIL_PORT)')
        ->and(file_get_contents("$dir/.env"))->toContain('REVERB_SCALING=false', 'MAIL_PORT=587');
});

it('reports a single new setting in the singular', function () {
    $dir = updateDirectory("APP_NAME=Databasify\n", "APP_NAME=Databasify\nREVERB_SCALING=false\n");

    $output = installerScript(". \"\$SOURCE/update.sh\" --functions-only; LOG=/dev/null; run_step 'Adding new settings' '' add_new_settings", ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain('✓ 1 new setting added (REVERB_SCALING)');
});

it('prints nothing and keeps .env when no setting is missing', function () {
    $dir = updateDirectory("APP_NAME=Databasify\n", "APP_NAME=Databasify\n");

    $output = installerScript('. "$SOURCE/update.sh" --functions-only; LOG=/dev/null; settings_step', ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toBe('')
        ->and(file_get_contents("$dir/.env"))->toBe("APP_NAME=Databasify\n");
});

it('adds a repeated setting only once', function () {
    $dir = updateDirectory("APP_NAME=Databasify\n", "APP_NAME=Databasify\nMAIL_PORT=587\nMAIL_PORT=587\n");

    $output = installerScript(". \"\$SOURCE/update.sh\" --functions-only; LOG=/dev/null; run_step 'Adding new settings' '' add_new_settings", ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain('✓ 1 new setting added (MAIL_PORT)')
        ->and(substr_count(file_get_contents("$dir/.env"), 'MAIL_PORT='))->toBe(1);
});

it('generates new credentials when .env has no key and no storage volume exists', function () {
    $dir = updateDirectory("APP_KEY=\nREVERB_APP_ID=\nREVERB_APP_KEY=\nREVERB_APP_SECRET=\n", '');

    $output = installerScript(dockerWithoutStorageVolume().'; LOG=/dev/null; secrets_step', ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain("✓ App key and Reverb credentials generated in $dir/.env")
        ->and(file_get_contents("$dir/.env"))->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m')->toMatch('/^REVERB_APP_SECRET=[0-9a-f]{64}$/m');
});

it('leaves .env alone and asks Docker nothing when it already holds the key', function () {
    $dir = updateDirectory(filledSecrets(), '');

    $result = installerScript('docker() { echo DOCKER; exit 9; }; LOG=/dev/null; secrets_step', ['DATABASIFY_DIR' => $dir]);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe('')
        ->and(file_get_contents("$dir/.env"))->toBe(filledSecrets());
});

it('prints nothing when the Horizon workers already fit the server', function () {
    $dir = updateDirectory(workersFor2CpusAnd4Gb(), '');

    $output = installerScript('horizon_step', ['DATABASIFY_DIR' => $dir, 'HOST_ROOT' => fakeHost(2, 4096)])->output();

    expect($output)->toBe('')
        ->and(file_get_contents("$dir/.env"))->toBe(workersFor2CpusAnd4Gb());
});

it('fills empty Horizon workers without asking', function () {
    $dir = updateDirectory(preg_replace('/=\d+$/m', '=', workersFor2CpusAnd4Gb()), '');

    $output = installerScript('has_terminal() { return 1; }; horizon_step', ['DATABASIFY_DIR' => $dir, 'HOST_ROOT' => fakeHost(2, 4096)])->output();

    expect($output)->toBe("    ✓ Horizon workers updated for 2 CPUs and 4096 MB\n")
        ->and(file_get_contents("$dir/.env"))->toBe(workersFor2CpusAnd4Gb());
});

it('applies changed Horizon workers when DATABASIFY_YES is true', function () {
    $dir = updateDirectory(workersBelowWhatTheServerFits(), '');

    $output = installerScript('horizon_step', ['DATABASIFY_DIR' => $dir, 'HOST_ROOT' => fakeHost(2, 4096), 'DATABASIFY_YES' => 'true'])->output();

    expect($output)->toBe("    ✓ Horizon workers updated for 2 CPUs and 4096 MB\n")
        ->and(file_get_contents("$dir/.env"))->toBe(workersFor2CpusAnd4Gb());
});

it('keeps changed Horizon workers when it cannot ask', function () {
    $env = workersBelowWhatTheServerFits();
    $dir = updateDirectory($env, '');

    $output = installerScript('has_terminal() { return 1; }; horizon_step', ['DATABASIFY_DIR' => $dir, 'HOST_ROOT' => fakeHost(2, 4096), 'DATABASIFY_YES' => 'false'])->output();

    expect($output)->toBe("    – Horizon workers kept (run again to apply: default 1 → 2, databases 3 → 6)\n")
        ->and(file_get_contents("$dir/.env"))->toBe($env);
});

it('refuses to generate a new key while the storage volume exists or Docker does not answer', function (string $docker) {
    $env = "APP_KEY=\nREVERB_APP_ID=\nREVERB_APP_KEY=\nREVERB_APP_SECRET=\n";
    $dir = updateDirectory($env, '');

    $result = installerScript("$docker; LOG=/dev/null; secrets_step", ['DATABASIFY_DIR' => $dir]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain("✗ APP_KEY is missing from $dir/.env")->toContain('from your backup')
        ->and(file_get_contents("$dir/.env"))->toBe($env);
})->with([
    'volume exists' => [dockerWithStorageVolume()],
    'docker unreachable' => ['docker() { return 1; }'],
]);

it('sizes the Horizon workers with the overrides set in .env', function () {
    $dir = updateDirectory("DATABASIFY_CPUS=4\n".workersFor2CpusAnd4Gb(), '');

    $output = installerScript('horizon_step', ['DATABASIFY_DIR' => $dir, 'HOST_ROOT' => fakeHost(2, 4096), 'DATABASIFY_YES' => 'true'])->output();

    expect($output)->toBe("    ✓ Horizon workers updated for 4 CPUs and 4096 MB\n")
        ->and(file_get_contents("$dir/.env"))->toContain('HORIZON_DEFAULT_MAX_PROCESSES=3', 'HORIZON_MONITORING_MAX_PROCESSES=7', 'HORIZON_DATABASES_MAX_PROCESSES=10');
});

it('keeps reading the piped script after asking Docker about the storage volume', function () {
    $dir = updateDirectory("APP_KEY=\nREVERB_APP_ID=\nREVERB_APP_KEY=\nREVERB_APP_SECRET=\n", '');
    $docker = 'docker() { cat > /dev/null; [ "$1" = info ]; }';

    $output = installerScript("$docker; LOG=/dev/null; secrets_step\necho AFTER", ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain('generated in')->toContain('AFTER');
});

it('generates only the missing Reverb credentials when .env already holds the app key', function () {
    $dir = updateDirectory("APP_KEY=base64:kept=\nREVERB_APP_ID=\nREVERB_APP_KEY=\nREVERB_APP_SECRET=\n", '');

    $result = installerScript(dockerWithStorageVolume().'; LOG=/dev/null; secrets_step', ['DATABASIFY_DIR' => $dir]);

    expect($result->exitCode())->toBe(0)
        ->and(file_get_contents("$dir/.env"))->toContain("APP_KEY=base64:kept=\n")->toMatch('/^REVERB_APP_SECRET=[0-9a-f]{64}$/m')
        ->and(substr(sprintf('%o', fileperms("$dir/.env")), -3))->toBe('600');
});

it('adds a new setting on its own line when .env does not end with a newline', function () {
    $dir = updateDirectory('APP_NAME=Databasify', "APP_NAME=Databasify\nMAIL_PORT=587\n");

    installerScript('. "$SOURCE/update.sh" --functions-only; LOG=/dev/null; add_new_settings', ['DATABASIFY_DIR' => $dir])->throw();

    expect(file_get_contents("$dir/.env"))->toBe("APP_NAME=Databasify\nMAIL_PORT=587\n");
});

it('stops with a clear line when the Horizon sizing cannot be downloaded', function () {
    $dir = updateDirectory(workersFor2CpusAnd4Gb(), '');

    $result = installerScript('SOURCE='.temporaryDirectory().'; LOG=/dev/null; horizon_step', ['DATABASIFY_DIR' => $dir]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('✗ Downloading the Horizon sizing failed')
        ->and(file_get_contents("$dir/.env"))->toBe(workersFor2CpusAnd4Gb());
});
