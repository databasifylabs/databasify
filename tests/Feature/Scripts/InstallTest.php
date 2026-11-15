<?php

declare(strict_types=1);

it('cleans up a typed domain', function (string $input) {
    expect(installerScript(". \"\$SOURCE/install.sh\" --functions-only; domain_from '$input'")->output())->toBe('db.example.com');
})->with(['db.example.com', 'https://db.example.com/', ' http://db.example.com ', 'https://db.example.com/app/']);

it('uses the domain from the environment without asking', function () {
    expect(installerScript('. "$SOURCE/install.sh" --functions-only; ask_domain 203.0.113.10; printf "[%s]" "$DOMAIN"', ['DATABASIFY_DOMAIN' => ' https://db.example.com/ '])->output())->toBe('[db.example.com]');
});

it('uses the address when the environment sets an empty domain', function () {
    expect(installerScript('. "$SOURCE/install.sh" --functions-only; ask_domain 203.0.113.10; printf "[%s]" "$DOMAIN"', ['DATABASIFY_DOMAIN' => ''])->output())->toBe('[]');
});

it('reads the domain from the terminal in its own process, never inside a command substitution', function () {
    $script = (string) file_get_contents(base_path('scripts/install.sh'));

    expect($script)->not->toMatch('/\$\(\s*ask_domain/')
        ->and($script)->toContain("\nask_domain \"\$address\"\n");
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

function ownerDockerStub(string $calls, string $stdin, int $status = 0): string
{
    return "docker() { echo \"\$*\" >> $calls; cat > $stdin; return $status; }";
}

it('reads the owner settings and removes them from the environment', function () {
    $output = installerScript('. "$SOURCE/install.sh" --functions-only; read_owner_settings; printf "[%s|%s|%s]" "$OWNER_NAME" "$OWNER_EMAIL" "$OWNER_PASSWORD"; env | grep -c "^DATABASIFY_OWNER_" || true', [
        'DATABASIFY_OWNER_NAME' => 'Owner',
        'DATABASIFY_OWNER_EMAIL' => 'owner@example.com',
        'DATABASIFY_OWNER_PASSWORD' => 'long-enough-password',
    ])->output();

    expect($output)->toContain('[Owner|owner@example.com|long-enough-password]')
        ->and(trim(str_replace('[Owner|owner@example.com|long-enough-password]', '', $output)))->toBe('0');
});

it('refuses a partial owner before changing anything', function () {
    $result = installerScript('. "$SOURCE/install.sh" --functions-only; read_owner_settings; require_complete_owner', ['DATABASIFY_OWNER_EMAIL' => 'owner@example.com']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('Set DATABASIFY_OWNER_NAME and DATABASIFY_OWNER_EMAIL, and type the password when asked');
});

it('accepts no owner settings or all three', function (array $env) {
    expect(installerScript('. "$SOURCE/install.sh" --functions-only; read_owner_settings; require_complete_owner', $env)->exitCode())->toBe(0);
})->with([
    'none' => [[]],
    'all three' => [['DATABASIFY_OWNER_NAME' => 'Owner', 'DATABASIFY_OWNER_EMAIL' => 'owner@example.com', 'DATABASIFY_OWNER_PASSWORD' => 'long-enough-password']],
]);

it('creates the owner in the app container with the password on standard input only', function () {
    $dir = temporaryDirectory();
    $calls = temporaryFile();
    $stdin = temporaryFile();

    $result = installerScript(ownerDockerStub($calls, $stdin).'; . "$SOURCE/install.sh" --functions-only; read_owner_settings; STEP_RESULT_FILE=$(mktemp); create_owner', [
        'DATABASIFY_DIR' => $dir,
        'DATABASIFY_OWNER_NAME' => 'Owner',
        'DATABASIFY_OWNER_EMAIL' => 'owner@example.com',
        'DATABASIFY_OWNER_PASSWORD' => 'long-enough-password',
    ]);

    expect($result->exitCode())->toBe(0)
        ->and((string) file_get_contents($calls))->toContain('compose exec -T app databasify artisan setup:owner --name=Owner --email=owner@example.com')
        ->not->toContain('long-enough-password')
        ->and((string) file_get_contents($stdin))->toBe('long-enough-password');
});

it('fails the install when the owner cannot be created', function () {
    $dir = temporaryDirectory();

    $result = installerScript(ownerDockerStub(temporaryFile(), temporaryFile(), 1).'; . "$SOURCE/install.sh" --functions-only; read_owner_settings; LOG=$(mktemp); owner_step; echo REACHED', [
        'DATABASIFY_DIR' => $dir,
        'DATABASIFY_OWNER_NAME' => 'Owner',
        'DATABASIFY_OWNER_EMAIL' => 'owner@example.com',
        'DATABASIFY_OWNER_PASSWORD' => 'long-enough-password',
    ]);

    expect($result->exitCode())->not->toBe(0)
        ->and($result->output())->toContain('Creating the owner account failed')
        ->not->toContain('REACHED');
});

it('skips the owner step and keeps the setup hint when no owner is given', function () {
    $output = installerScript('docker() { echo CALLED; }; . "$SOURCE/install.sh" --functions-only; read_owner_settings; owner_step; next_step_row')->output();

    expect($output)->not->toContain('CALLED')
        ->and($output)->toContain('Next|Create your admin account on that page');
});

it('tells the owner to sign in when the owner was created at install', function () {
    $output = installerScript('. "$SOURCE/install.sh" --functions-only; read_owner_settings; next_step_row', [
        'DATABASIFY_OWNER_NAME' => 'Owner',
        'DATABASIFY_OWNER_EMAIL' => 'owner@example.com',
        'DATABASIFY_OWNER_PASSWORD' => 'long-enough-password',
    ])->output();

    expect($output)->toBe('Next|Sign in as owner@example.com');
});
