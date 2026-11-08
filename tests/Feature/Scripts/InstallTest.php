<?php

declare(strict_types=1);

it('cleans up a typed domain', function (string $input) {
    expect(installerScript(". \"\$SOURCE/install.sh\" --functions-only; domain_from '$input'")->output())->toBe('db.example.com');
})->with(['db.example.com', 'https://db.example.com/', ' http://db.example.com ', 'https://db.example.com/app/']);

it('uses the domain from the environment without asking', function () {
    expect(installerScript('. "$SOURCE/install.sh" --functions-only; ask_domain 203.0.113.10', ['DATABASIFY_DOMAIN' => 'db.example.com'])->output())->toBe('db.example.com');
});

it('uses the address when the environment sets an empty domain', function () {
    expect(installerScript('. "$SOURCE/install.sh" --functions-only; ask_domain 203.0.113.10', ['DATABASIFY_DOMAIN' => ''])->output())->toBe('');
});

it('detects an install only when both .env and compose.yaml exist', function (array $files, int $status) {
    $dir = temporaryDirectory();
    collect($files)->each(fn (string $file): int|false => file_put_contents("$dir/$file", ''));

    $result = installerScript('. "$SOURCE/install.sh" --functions-only; already_installed', ['DATABASIFY_DIR' => $dir]);

    expect($result->exitCode())->toBe($status);
})->with([
    'both files' => [['.env', 'compose.yaml'], 0],
    'only .env' => [['.env'], 1],
    'only compose.yaml' => [['compose.yaml'], 1],
]);

it('stops with the update command and changes nothing when already installed', function () {
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env", "APP_URL=https://kept.example.com\n");
    file_put_contents("$dir/compose.yaml", "services: {}\n");

    $result = installerScript('. "$SOURCE/install.sh" --functions-only; stop_when_installed; echo AFTER', ['DATABASIFY_DIR' => $dir]);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain("✓ Databasify is already installed in $dir")
        ->toContain('To update it, run:')
        ->toContain('curl -fsSL https://get.databasify.io/update.sh | sudo bash')
        ->not->toContain('AFTER')
        ->and(file_get_contents("$dir/.env"))->toBe("APP_URL=https://kept.example.com\n")
        ->and(file_get_contents("$dir/compose.yaml"))->toBe("services: {}\n")
        ->and(file_exists("$dir/install.log"))->toBeFalse();
});

it('continues the install when only .env exists', function () {
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env", '');

    $output = installerScript('. "$SOURCE/install.sh" --functions-only; stop_when_installed; echo AFTER', ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toBe("AFTER\n");
});

it('writes the settings from the template with the chosen address', function (string $domain, string $url) {
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env.example", "APP_URL=http://localhost\nAPP_NAME=Databasify\n");

    installerScript('. "$SOURCE/install.sh" --functions-only; '.dockerWithoutStorageVolume()."; resolve_secrets; write_env '$domain' 203.0.113.10", ['DATABASIFY_DIR' => $dir, 'HOST_ROOT' => fakeHost(2, 4096)])->throw();

    expect(file_get_contents("$dir/.env"))->toContain("APP_URL=$url\n", 'APP_NAME=Databasify')
        ->and(substr(sprintf('%o', fileperms("$dir/.env")), -3))->toBe('600')
        ->and(file_exists("$dir/.env.bak"))->toBeFalse();
})->with([
    ['db.example.com', 'https://db.example.com'],
    ['', 'http://203.0.113.10'],
]);

it('writes a new app key, Reverb credentials and Horizon workers sized for the server', function () {
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env.example", file_get_contents(base_path('scripts/env.example')));

    installerScript('. "$SOURCE/install.sh" --functions-only; '.dockerWithoutStorageVolume().'; resolve_secrets; write_env db.example.com 203.0.113.10', ['DATABASIFY_DIR' => $dir, 'HOST_ROOT' => fakeHost(2, 4096)])->throw();

    $env = file_get_contents("$dir/.env");
    expect($env)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m')
        ->toMatch('/^REVERB_APP_ID=[0-9a-f]{8}$/m')
        ->toMatch('/^REVERB_APP_KEY=[0-9a-f]{32}$/m')
        ->toMatch('/^REVERB_APP_SECRET=[0-9a-f]{64}$/m')
        ->toContain(workersFor2CpusAnd4Gb());
});

it('refuses a fresh install over an existing storage volume', function () {
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env.example", file_get_contents(base_path('scripts/env.example')));

    $result = installerScript('. "$SOURCE/install.sh" --functions-only; '.dockerWithStorageVolume().'; LOG=/dev/null; settings_step db.example.com 203.0.113.10', ['DATABASIFY_DIR' => $dir, 'HOST_ROOT' => fakeHost(2, 4096)]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain("✗ APP_KEY is missing from $dir/.env")
        ->and(file_exists("$dir/.env"))->toBeFalse();
});

it('shows as many log lines as the step asks for', function () {
    $log = temporaryFile(collect(range(1, 60))->map(fn (int $line): string => "line $line")->implode("\n")."\n");

    $output = installerScript("LOG=$log; STEP_LOG_LINES=50 step_failed 'Waiting until healthy failed'")->output();

    expect($output)->toContain('line 11')->not->toContain('line 10'.PHP_EOL);
});
