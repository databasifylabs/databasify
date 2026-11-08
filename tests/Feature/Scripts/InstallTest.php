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

it('keeps an existing .env and reports it', function () {
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env", "APP_URL=https://kept.example.com\n");

    $output = installerScript('. "$SOURCE/install.sh" --functions-only; settings_step db.example.com 203.0.113.10', ['DATABASIFY_DIR' => $dir])->output();

    expect($output)->toContain("– Settings kept from $dir/.env")->not->toContain('(skipped)')
        ->and(file_get_contents("$dir/.env"))->toBe("APP_URL=https://kept.example.com\n");
});

it('writes the settings from the template with the chosen address', function (string $domain, string $url) {
    $dir = temporaryDirectory();
    file_put_contents("$dir/.env.example", "APP_URL=http://localhost\nAPP_NAME=Databasify\n");

    installerScript(". \"\$SOURCE/install.sh\" --functions-only; write_env '$domain' 203.0.113.10", ['DATABASIFY_DIR' => $dir])->throw();

    expect(file_get_contents("$dir/.env"))->toContain("APP_URL=$url\n", 'APP_NAME=Databasify')
        ->and(substr(sprintf('%o', fileperms("$dir/.env")), -3))->toBe('600')
        ->and(file_exists("$dir/.env.bak"))->toBeFalse();
})->with([
    ['db.example.com', 'https://db.example.com'],
    ['', 'http://203.0.113.10'],
]);

it('shows as many log lines as the step asks for', function () {
    $log = temporaryFile(collect(range(1, 60))->map(fn (int $line): string => "line $line")->implode("\n")."\n");

    $output = installerScript("LOG=$log; STEP_LOG_LINES=50 step_failed 'Waiting until healthy failed'")->output();

    expect($output)->toContain('line 11')->not->toContain('line 10'.PHP_EOL);
});
